-- Private common actor check for new platform commands. No user session is persisted into a job.
CREATE FUNCTION axiom_auth.actor(token text,configured_site bigint)
RETURNS TABLE(user_id bigint,role text,credential_fingerprint text)
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,axiom_auth,pg_temp AS $actor$
DECLARE principal record;
BEGIN
    IF configured_site IS DISTINCT FROM axiom_auth.site() THEN RETURN; END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended('axiom-auth:'||configured_site,0));
    SELECT u.id,m.role,s.credential_fingerprint,s.expires_at,s.last_seen_at INTO principal
        FROM axiom_auth.sessions s JOIN axiom_identity.users u ON u.id=s.user_id
        JOIN axiom_identity.credentials c ON c.user_id=u.id
        JOIN axiom_identity.memberships m ON m.site_id=s.site_id AND m.user_id=u.id
        WHERE s.token_hash=token AND s.site_id=configured_site AND u.disabled_at IS NULL AND m.disabled_at IS NULL
        AND s.credential_fingerprint=encode(sha256(convert_to(c.password_hash,'UTF8')),'hex')
        FOR UPDATE OF s FOR SHARE OF u,c,m;
    IF NOT FOUND OR principal.expires_at<=clock_timestamp()
        OR principal.last_seen_at<=clock_timestamp()-interval '30 minutes' THEN RETURN; END IF;
    UPDATE axiom_auth.sessions SET last_seen_at=least(clock_timestamp(),expires_at) WHERE token_hash=token;
    RETURN QUERY SELECT principal.id,principal.role,principal.credential_fingerprint;
END;
$actor$;
REVOKE ALL ON FUNCTION axiom_auth.actor(text,bigint) FROM PUBLIC,axiom_authenticator;

CREATE SCHEMA axiom_jobs AUTHORIZATION axiom_owner;
REVOKE ALL ON SCHEMA axiom_jobs FROM PUBLIC;
CREATE TABLE axiom_jobs.runners(database_role oid PRIMARY KEY,site_id bigint NOT NULL REFERENCES axiom_identity.sites(id));
CREATE TABLE axiom_jobs.subscriptions(
    id text PRIMARY KEY DEFAULT replace(gen_random_uuid()::text,'-',''),
    site_id bigint NOT NULL,
    article_id bigint NOT NULL,
    installation text NOT NULL CHECK(installation ~ '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$'),
    generation bigint NOT NULL CHECK(generation>0),
    enabled boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    expires_at timestamptz NOT NULL,
    CHECK(expires_at>created_at AND expires_at<=created_at+interval '24 hours'),
    UNIQUE(site_id,article_id,installation),
    FOREIGN KEY(site_id,article_id) REFERENCES axiom_content.articles(site_id,id)
);
CREATE TABLE axiom_jobs.deliveries(
    id text PRIMARY KEY DEFAULT replace(gen_random_uuid()::text,'-',''),
    site_id bigint NOT NULL,
    event_id text NOT NULL REFERENCES axiom_content.outbox(event_id),
    subscription text NOT NULL REFERENCES axiom_jobs.subscriptions(id),
    generation bigint NOT NULL,
    status text NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','leased','done','dead','cancelled')),
    attempts integer NOT NULL DEFAULT 0 CHECK(attempts BETWEEN 0 AND 10),
    available_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    expires_at timestamptz NOT NULL,
    lease_token text,
    lease_until timestamptz,
    finished_at timestamptz,
    last_error text CHECK(last_error IN ('unavailable','deadline_exceeded','forbidden','expired','quota_exceeded')),
    UNIQUE(event_id,subscription,generation),
    CHECK((status='leased')=(lease_token IS NOT NULL AND lease_until IS NOT NULL))
);
CREATE INDEX delivery_due ON axiom_jobs.deliveries(site_id,status,available_at);
CREATE TABLE axiom_jobs.inbox(
    site_id bigint NOT NULL,
    subscription text NOT NULL REFERENCES axiom_jobs.subscriptions(id),
    generation bigint NOT NULL,
    event_id text NOT NULL,
    article_id bigint NOT NULL,
    revision_id text NOT NULL,
    occurred_at timestamptz NOT NULL,
    received_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY(subscription,generation,event_id)
);
CREATE TABLE axiom_jobs.schedules(
    site_id bigint NOT NULL,
    article_id bigint NOT NULL,
    actor bigint NOT NULL,
    credential_fingerprint text NOT NULL,
    expected_revision text NOT NULL,
    expected_version bigint NOT NULL,
    due_at timestamptz NOT NULL,
    status text NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','done','cancelled','conflict','forbidden')),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    finished_at timestamptz,
    PRIMARY KEY(site_id,article_id),
    FOREIGN KEY(site_id,article_id) REFERENCES axiom_content.articles(site_id,id),
    FOREIGN KEY(site_id,actor) REFERENCES axiom_identity.memberships(site_id,user_id)
);
CREATE FUNCTION axiom_jobs.site() RETURNS bigint LANGUAGE sql STABLE SECURITY DEFINER
SET search_path=pg_catalog,axiom_jobs,pg_temp AS $scope$
    SELECT site_id FROM axiom_jobs.runners WHERE database_role=session_user::regrole::oid
$scope$;

CREATE FUNCTION axiom_jobs.claim() RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog,axiom_jobs,pg_temp AS $claim$
DECLARE scope bigint:=axiom_jobs.site(); task axiom_jobs.deliveries%ROWTYPE; capacity integer;
BEGIN
    IF scope IS NULL THEN RAISE EXCEPTION 'Invalid executor scope' USING ERRCODE='42501'; END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended('axiom-auth:'||scope,0));
    DELETE FROM axiom_jobs.inbox WHERE site_id=scope AND received_at<=clock_timestamp()-interval '7 days';
    DELETE FROM axiom_jobs.deliveries WHERE site_id=scope AND finished_at<=clock_timestamp()-interval '7 days';
    UPDATE axiom_jobs.deliveries SET status=CASE WHEN attempts>=10 OR expires_at<=clock_timestamp()
        THEN 'dead' ELSE 'pending' END,lease_token=NULL,lease_until=NULL,
        available_at=clock_timestamp()+make_interval(secs=>least(3600,5*power(2,attempts))::integer),
        finished_at=CASE WHEN attempts>=10 OR expires_at<=clock_timestamp() THEN clock_timestamp() ELSE NULL END,
        last_error='deadline_exceeded'
        WHERE site_id=scope AND status='leased' AND lease_until<=clock_timestamp();
    UPDATE axiom_jobs.deliveries SET status='dead',finished_at=clock_timestamp(),last_error='expired'
        WHERE site_id=scope AND status='pending' AND expires_at<=clock_timestamp();
    SELECT greatest(0,4096-count(*)::integer) INTO capacity FROM axiom_jobs.deliveries WHERE site_id=scope;
    DELETE FROM axiom_content.outbox o WHERE o.site_id=scope AND o.occurred_at<=clock_timestamp()-interval '24 hours'
        AND NOT EXISTS(SELECT FROM axiom_jobs.deliveries d WHERE d.event_id=o.event_id);
    INSERT INTO axiom_jobs.deliveries(site_id,event_id,subscription,generation,expires_at)
        SELECT scope,o.event_id,s.id,s.generation,o.occurred_at+interval '24 hours'
        FROM axiom_content.outbox o JOIN axiom_jobs.subscriptions s
            ON s.site_id=o.site_id AND s.article_id=o.article_id
        WHERE o.site_id=scope AND o.event='content.published.v1' AND s.enabled
        AND s.expires_at>clock_timestamp() AND o.occurred_at>=s.created_at
        AND o.occurred_at>clock_timestamp()-interval '24 hours'
        AND NOT EXISTS(SELECT FROM axiom_jobs.deliveries d WHERE d.event_id=o.event_id
            AND d.subscription=s.id AND d.generation=s.generation)
        ORDER BY o.occurred_at,o.event_id,s.id LIMIT capacity ON CONFLICT DO NOTHING;
    SELECT * INTO task FROM axiom_jobs.deliveries d WHERE d.site_id=scope AND d.status='pending'
        AND d.available_at<=clock_timestamp() ORDER BY d.available_at,d.id FOR UPDATE SKIP LOCKED LIMIT 1;
    IF NOT FOUND THEN RETURN '{}'::jsonb; END IF;
    UPDATE axiom_jobs.deliveries SET status='leased',attempts=attempts+1,
        lease_token=replace(gen_random_uuid()::text,'-',''),lease_until=clock_timestamp()+interval '30 seconds'
        WHERE id=task.id RETURNING * INTO task;
    RETURN jsonb_build_object('id',task.id,'site_id',scope::text,'lease',task.lease_token,
        'event_id',task.event_id,'attempt',task.attempts,'deadline',task.lease_until);
END;
$claim$;

CREATE FUNCTION axiom_jobs.finish(handle text,outcome text) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog,axiom_jobs,pg_temp AS $finish$
DECLARE scope bigint:=axiom_jobs.site(); task axiom_jobs.deliveries%ROWTYPE; sub axiom_jobs.subscriptions%ROWTYPE;
    event axiom_content.outbox%ROWTYPE; article axiom_content.articles%ROWTYPE;
BEGIN
    IF scope IS NULL THEN RAISE EXCEPTION 'Invalid executor scope' USING ERRCODE='42501'; END IF;
    IF outcome IS NULL OR outcome NOT IN ('delivered','unavailable','deadline_exceeded') THEN
        RETURN jsonb_build_object('error','invalid_request'); END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended('axiom-auth:'||scope,0));
    SELECT * INTO task FROM axiom_jobs.deliveries WHERE site_id=scope AND status='leased'
        AND lease_token=handle AND lease_until>clock_timestamp() FOR UPDATE;
    IF NOT FOUND THEN RETURN jsonb_build_object('error','conflict'); END IF;
    SELECT * INTO sub FROM axiom_jobs.subscriptions WHERE id=task.subscription FOR SHARE;
    SELECT * INTO event FROM axiom_content.outbox WHERE event_id=task.event_id;
    SELECT * INTO article FROM axiom_content.articles WHERE site_id=scope AND id=event.article_id FOR SHARE;
    IF task.lease_until<=clock_timestamp() THEN RETURN jsonb_build_object('error','conflict'); END IF;
    IF NOT sub.enabled OR sub.site_id<>scope OR sub.generation<>task.generation OR sub.expires_at<=clock_timestamp()
        OR task.expires_at<=clock_timestamp() OR article.status<>'published'
        OR article.published_revision_id<>event.revision_id OR article.published_at>clock_timestamp() THEN
        UPDATE axiom_jobs.deliveries SET status='cancelled',lease_token=NULL,lease_until=NULL,
            finished_at=clock_timestamp(),last_error='forbidden' WHERE id=task.id;
        RETURN jsonb_build_object('outcome','cancelled');
    END IF;
    IF outcome='delivered' THEN
        IF (SELECT count(*) FROM axiom_jobs.inbox WHERE site_id=scope)>=4096 THEN
            outcome:='unavailable';
        ELSE
            INSERT INTO axiom_jobs.inbox(site_id,subscription,generation,event_id,article_id,revision_id,occurred_at)
                VALUES(scope,sub.id,sub.generation,event.event_id,event.article_id,event.revision_id,event.occurred_at)
                ON CONFLICT DO NOTHING;
            UPDATE axiom_jobs.deliveries SET status='done',lease_token=NULL,lease_until=NULL,
                finished_at=clock_timestamp(),last_error=NULL WHERE id=task.id;
            RETURN jsonb_build_object('outcome','delivered');
        END IF;
    END IF;
    UPDATE axiom_jobs.deliveries SET status=CASE WHEN attempts>=10 THEN 'dead' ELSE 'pending' END,
        lease_token=NULL,lease_until=NULL,last_error=outcome,
        finished_at=CASE WHEN attempts>=10 THEN clock_timestamp() ELSE NULL END,
        available_at=clock_timestamp()+make_interval(secs=>least(3600,5*power(2,attempts))::integer) WHERE id=task.id;
    RETURN jsonb_build_object('outcome',CASE WHEN task.attempts>=10 THEN 'dead' ELSE 'retry' END);
END;
$finish$;

CREATE FUNCTION axiom_jobs.schedule(token text,configured_site bigint,article_id bigint,
    expected_revision text,expected_version bigint,due_at timestamptz,command_key text)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,axiom_jobs,pg_temp AS $schedule$
DECLARE principal record; item axiom_content.articles%ROWTYPE; digest text; receipt axiom_content.command_receipts%ROWTYPE;
    result jsonb;
BEGIN
    SELECT * INTO principal FROM axiom_auth.actor(token,configured_site);
    IF NOT FOUND THEN RETURN jsonb_build_object('error','unauthenticated'); END IF;
    IF principal.role NOT IN ('owner','editor') THEN RETURN jsonb_build_object('error','forbidden'); END IF;
    IF command_key IS NULL OR command_key !~ '^[a-f0-9]{64}$' OR octet_length(command_key)<>64
        OR due_at IS NULL OR expected_revision IS NULL OR expected_version IS NULL THEN
        RETURN jsonb_build_object('error','invalid_request'); END IF;
    SELECT * INTO item FROM axiom_content.articles a WHERE a.site_id=configured_site AND a.id=article_id FOR UPDATE;
    IF NOT FOUND THEN RETURN jsonb_build_object('error','not_found'); END IF;
    DELETE FROM axiom_content.command_receipts WHERE site_id=configured_site
        AND created_at<=clock_timestamp()-interval '14 days';
    digest:=encode(sha256(convert_to(jsonb_build_array('schedule',article_id,expected_revision,expected_version,due_at)::text,
        'UTF8')),'hex');
    SELECT * INTO receipt FROM axiom_content.command_receipts r WHERE r.site_id=configured_site
        AND r.user_id=principal.user_id AND r.key=command_key;
    IF FOUND THEN
        IF receipt.digest<>digest OR receipt.created_at<=clock_timestamp()-interval '7 days' THEN
            RETURN jsonb_build_object('error','conflict'); END IF;
        RETURN receipt.result;
    END IF;
    IF due_at<=clock_timestamp() OR due_at>clock_timestamp()+interval '30 days' THEN
        RETURN jsonb_build_object('error','invalid_request'); END IF;
    IF item.current_revision_id<>expected_revision OR item.lock_version<>expected_version THEN
        RETURN jsonb_build_object('error','conflict'); END IF;
    IF ((SELECT count(*) FROM axiom_jobs.schedules WHERE site_id=configured_site)>=128
        AND NOT EXISTS(SELECT FROM axiom_jobs.schedules s WHERE s.site_id=configured_site AND s.article_id=schedule.article_id))
        OR (SELECT count(*) FROM axiom_content.command_receipts WHERE site_id=configured_site)>=4096 THEN
        RETURN jsonb_build_object('error','quota_exceeded'); END IF;
    INSERT INTO axiom_jobs.schedules(site_id,article_id,actor,credential_fingerprint,expected_revision,expected_version,due_at)
        VALUES(configured_site,article_id,principal.user_id,principal.credential_fingerprint,
            expected_revision,expected_version,due_at)
        ON CONFLICT ON CONSTRAINT schedules_pkey DO UPDATE SET actor=excluded.actor,
            credential_fingerprint=excluded.credential_fingerprint,expected_revision=excluded.expected_revision,
            expected_version=excluded.expected_version,due_at=excluded.due_at,status='pending',
            created_at=clock_timestamp(),finished_at=NULL;
    result:=jsonb_build_object('id',article_id::text,'site_id',configured_site::text,'scheduled_at',due_at);
    INSERT INTO axiom_content.command_receipts(site_id,user_id,key,digest,article_id,result)
        VALUES(configured_site,principal.user_id,command_key,digest,article_id,result);
    RETURN result;
END;
$schedule$;

CREATE FUNCTION axiom_jobs.cancel(token text,configured_site bigint,target bigint)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,axiom_jobs,pg_temp AS $cancel$
DECLARE principal record;
BEGIN
    SELECT * INTO principal FROM axiom_auth.actor(token,configured_site);
    IF NOT FOUND THEN RETURN jsonb_build_object('error','unauthenticated'); END IF;
    IF principal.role NOT IN ('owner','editor') THEN RETURN jsonb_build_object('error','forbidden'); END IF;
    UPDATE axiom_jobs.schedules SET status='cancelled',finished_at=clock_timestamp()
        WHERE site_id=configured_site AND article_id=target;
    IF NOT FOUND THEN RETURN jsonb_build_object('error','not_found'); END IF;
    RETURN jsonb_build_object('outcome','cancelled','site_id',configured_site::text);
END;
$cancel$;

CREATE FUNCTION axiom_jobs.tick() RETURNS integer LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog,axiom_jobs,pg_temp AS $tick$
DECLARE scope bigint:=axiom_jobs.site(); intent axiom_jobs.schedules%ROWTYPE;
    principal record; item axiom_content.articles%ROWTYPE; published integer:=0;
BEGIN
    IF scope IS NULL THEN RAISE EXCEPTION 'Invalid executor scope' USING ERRCODE='42501'; END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended('axiom-auth:'||scope,0));
    DELETE FROM axiom_jobs.schedules WHERE site_id=scope AND finished_at<=clock_timestamp()-interval '7 days';
    FOR intent IN SELECT * FROM axiom_jobs.schedules WHERE site_id=scope AND status='pending'
        AND due_at<=clock_timestamp() ORDER BY due_at LIMIT 32 FOR UPDATE LOOP
        SELECT u.id,m.role INTO principal FROM axiom_identity.users u
            JOIN axiom_identity.credentials c ON c.user_id=u.id
            JOIN axiom_identity.memberships m ON m.site_id=scope AND m.user_id=u.id
            WHERE u.id=intent.actor AND u.disabled_at IS NULL AND m.disabled_at IS NULL
            AND m.role IN ('owner','editor')
            AND encode(sha256(convert_to(c.password_hash,'UTF8')),'hex')=intent.credential_fingerprint
            FOR SHARE OF u,c,m;
        IF NOT FOUND THEN
            UPDATE axiom_jobs.schedules SET status='forbidden',finished_at=clock_timestamp()
                WHERE site_id=scope AND article_id=intent.article_id; CONTINUE;
        END IF;
        SELECT * INTO item FROM axiom_content.articles WHERE site_id=scope AND id=intent.article_id FOR UPDATE;
        IF NOT FOUND OR item.lock_version<>intent.expected_version OR item.current_revision_id<>intent.expected_revision
            OR item.lock_version>=999999999999999999 OR item.published_revision_id=item.current_revision_id THEN
            UPDATE axiom_jobs.schedules SET status='conflict',finished_at=clock_timestamp()
                WHERE site_id=scope AND article_id=intent.article_id; CONTINUE;
        END IF;
        IF (SELECT count(*) FROM axiom_content.outbox WHERE site_id=scope)>=10000 THEN EXIT; END IF;
        UPDATE axiom_content.articles SET status='published',published_revision_id=current_revision_id,
            published_at=clock_timestamp(),lock_version=lock_version+1,updated_at=clock_timestamp()
            WHERE site_id=scope AND id=item.id;
        INSERT INTO axiom_content.outbox(site_id,article_id,revision_id,event)
            VALUES(scope,item.id,item.current_revision_id,'content.published.v1');
        UPDATE axiom_jobs.schedules SET status='done',finished_at=clock_timestamp()
            WHERE site_id=scope AND article_id=item.id;
        published:=published+1;
    END LOOP;
    RETURN published;
END;
$tick$;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA axiom_jobs FROM PUBLIC;
GRANT USAGE ON SCHEMA axiom_jobs TO axiom_job_runner,axiom_authenticator;
GRANT EXECUTE ON FUNCTION axiom_jobs.site(),axiom_jobs.claim(),axiom_jobs.finish(text,text),axiom_jobs.tick()
    TO axiom_job_runner;
GRANT EXECUTE ON FUNCTION axiom_jobs.schedule(text,bigint,bigint,text,bigint,timestamptz,text),
    axiom_jobs.cancel(text,bigint,bigint) TO axiom_authenticator;
