CREATE TABLE axiom_content.command_receipts (
    site_id bigint NOT NULL REFERENCES axiom_identity.sites(id),
    user_id bigint NOT NULL REFERENCES axiom_identity.users(id),
    key text NOT NULL CHECK (key ~ '^[a-f0-9]{64}$' AND octet_length(key)=64),
    digest text NOT NULL,
    article_id bigint NOT NULL,
    result jsonb NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY(site_id,user_id,key)
);
CREATE TABLE axiom_content.outbox (
    event_id text PRIMARY KEY DEFAULT replace(gen_random_uuid()::text,'-',''),
    site_id bigint NOT NULL,
    article_id bigint NOT NULL,
    revision_id text NOT NULL,
    event text NOT NULL CHECK (event IN ('content.published.v1','content.unpublished.v1')),
    occurred_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    FOREIGN KEY(site_id,article_id,revision_id)
        REFERENCES axiom_content.revisions(site_id,article_id,revision_id)
);
-- One bounded, transactional command boundary. No caller-supplied actor or SQL is accepted.
CREATE FUNCTION axiom_content.command(
    session_token text, configured_site bigint, operation text, target bigint,
    expected_revision text, expected_version bigint, title_text text, body_text text,
    command_key text, new_revision text
) RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog,axiom_content,pg_temp AS $command$
DECLARE
    scope bigint;
    actor bigint;
    member_role text;
    item axiom_content.articles%ROWTYPE;
    receipt axiom_content.command_receipts%ROWTYPE;
    fingerprint text;
    result jsonb;
    next_revision_version bigint;
    event_revision text;
BEGIN
    SELECT site_id INTO scope FROM axiom_auth.sites
        WHERE database_role=session_user::regrole::oid FOR SHARE;
    IF scope IS NULL OR configured_site IS DISTINCT FROM scope THEN
        RETURN jsonb_build_object('error','unauthenticated');
    END IF;
    IF operation IS NULL OR operation NOT IN ('read','create','edit','publish','unpublish')
       OR target IS NULL OR target<0 OR octet_length(session_token) IS DISTINCT FROM 64
       OR session_token !~ '^[a-f0-9]{64}$' THEN
        RETURN jsonb_build_object('error','invalid_request');
    END IF;
    -- The same site lock as session creation prevents receipt/quota races and fixes lock order.
    PERFORM pg_advisory_xact_lock(hashtextextended('axiom-auth:'||scope,0));
    SELECT u.id,m.role INTO actor,member_role FROM axiom_auth.sessions s
        JOIN axiom_identity.users u ON u.id=s.user_id
        JOIN axiom_identity.credentials c ON c.user_id=u.id
        JOIN axiom_identity.memberships m ON m.site_id=s.site_id AND m.user_id=u.id
        WHERE s.site_id=scope AND s.token_hash=session_token AND u.disabled_at IS NULL AND m.disabled_at IS NULL
        AND s.expires_at>clock_timestamp() AND s.last_seen_at>clock_timestamp()-interval '30 minutes'
        AND s.credential_fingerprint=encode(sha256(convert_to(c.password_hash,'UTF8')),'hex')
        FOR UPDATE OF s FOR SHARE OF u,c,m;
    IF NOT FOUND THEN RETURN jsonb_build_object('error','unauthenticated'); END IF;
    IF operation<>'create' THEN
        SELECT * INTO item FROM axiom_content.articles a WHERE a.site_id=scope AND a.id=target FOR UPDATE;
        IF NOT FOUND OR (member_role='author' AND item.author_id<>actor) THEN
            RETURN jsonb_build_object('error','not_found');
        END IF;
    END IF;
    -- Wall-clock expiry may pass while identity/resource row locks are waiting.
    IF NOT EXISTS (SELECT FROM axiom_auth.sessions s WHERE s.token_hash=session_token
        AND s.expires_at>clock_timestamp() AND s.last_seen_at>clock_timestamp()-interval '30 minutes') THEN
        RETURN jsonb_build_object('error','unauthenticated');
    END IF;
    UPDATE axiom_auth.sessions SET last_seen_at=least(clock_timestamp(),expires_at) WHERE token_hash=session_token;
    IF operation='read' THEN
        SELECT jsonb_build_object('id',item.id::text,'site_id',scope::text,'author_id',item.author_id::text,
            'revision',r.revision_id,'version',item.lock_version::text,'status',item.status,
            'published_revision',item.published_revision_id,'title',r.title,'text',r.body)
        INTO result FROM axiom_content.revisions r
        WHERE r.site_id=scope AND r.article_id=item.id AND r.revision_id=item.current_revision_id;
        RETURN result;
    END IF;
    IF operation IN ('publish','unpublish') AND member_role NOT IN ('owner','editor') THEN
        RETURN jsonb_build_object('error','forbidden');
    END IF;
    IF command_key IS NULL OR command_key !~ '^[a-f0-9]{64}$' OR octet_length(command_key)<>64
       OR new_revision IS NULL OR new_revision !~ '^[a-f0-9]{64}$' OR octet_length(new_revision)<>64
       OR title_text IS NULL OR body_text IS NULL OR octet_length(title_text)>4096
       OR octet_length(body_text)>131072 OR expected_revision IS NULL OR expected_version IS NULL
       OR (operation='create' AND (target<>0 OR expected_revision<>'' OR expected_version<>0))
       OR (operation<>'create' AND (expected_revision !~ '^[a-f0-9]{64}$'
           OR octet_length(expected_revision)<>64 OR expected_version<1)) THEN
        RETURN jsonb_build_object('error','invalid_request');
    END IF;
    fingerprint := encode(sha256(convert_to(jsonb_build_array(operation,target,expected_revision,
        expected_version,title_text,body_text)::text,'UTF8')),'hex');
    DELETE FROM axiom_content.command_receipts WHERE site_id=scope AND created_at<=clock_timestamp()-interval '14 days';
    SELECT * INTO receipt FROM axiom_content.command_receipts r
        WHERE r.site_id=scope AND r.user_id=actor AND r.key=command_key;
    IF FOUND THEN
        -- Reauthorize the original resource, including create retries, before returning stored outcomes.
        SELECT * INTO item FROM axiom_content.articles a
            WHERE a.site_id=scope AND a.id=receipt.article_id FOR UPDATE;
        IF NOT FOUND OR (member_role='author' AND item.author_id<>actor) THEN
            RETURN jsonb_build_object('error','not_found');
        END IF;
        IF NOT EXISTS (SELECT FROM axiom_auth.sessions s WHERE s.token_hash=session_token
            AND s.expires_at>clock_timestamp()) THEN
            RETURN jsonb_build_object('error','unauthenticated');
        END IF;
        IF receipt.digest<>fingerprint OR receipt.created_at<=clock_timestamp()-interval '7 days' THEN
            RETURN jsonb_build_object('error','conflict');
        END IF;
        RETURN receipt.result;
    END IF;
    IF (SELECT count(*) FROM axiom_content.command_receipts WHERE site_id=scope)>=4096 THEN
        RETURN jsonb_build_object('error','quota_exceeded');
    END IF;
    IF operation<>'create' AND (item.current_revision_id<>expected_revision OR item.lock_version<>expected_version) THEN
        RETURN jsonb_build_object('error','conflict');
    END IF;
    IF operation='create' THEN
        IF (SELECT count(*) FROM axiom_content.articles WHERE site_id=scope)>=10000 THEN
            RETURN jsonb_build_object('error','quota_exceeded');
        END IF;
        INSERT INTO axiom_content.articles(site_id,author_id,current_revision_id)
            VALUES(scope,actor,new_revision) RETURNING * INTO item;
    ELSIF item.lock_version>=999999999999999999 THEN
        RETURN jsonb_build_object('error','quota_exceeded');
    END IF;
    IF operation IN ('create','edit') THEN
        SELECT coalesce(max(r.version),0)+1 INTO next_revision_version FROM axiom_content.revisions r
            WHERE r.site_id=scope AND r.article_id=item.id;
        IF next_revision_version>1000 THEN RETURN jsonb_build_object('error','quota_exceeded'); END IF;
        INSERT INTO axiom_content.revisions(site_id,article_id,revision_id,version,title,body,created_by)
            VALUES(scope,item.id,new_revision,next_revision_version,title_text,body_text,actor);
        UPDATE axiom_content.articles SET current_revision_id=new_revision,
            lock_version=lock_version+CASE WHEN operation='create' THEN 0 ELSE 1 END,updated_at=clock_timestamp()
            WHERE site_id=scope AND id=item.id RETURNING * INTO item;
    ELSE
        IF (operation='publish' AND item.published_revision_id=item.current_revision_id)
            OR (operation='unpublish' AND item.status<>'published') THEN
            RETURN jsonb_build_object('error','conflict');
        END IF;
        IF (SELECT count(*) FROM axiom_content.outbox WHERE site_id=scope)>=10000 THEN
            RETURN jsonb_build_object('error','quota_exceeded');
        END IF;
        event_revision := CASE WHEN operation='publish' THEN item.current_revision_id ELSE item.published_revision_id END;
        UPDATE axiom_content.articles SET status=CASE WHEN operation='publish' THEN 'published' ELSE 'draft' END,
            published_revision_id=CASE WHEN operation='publish' THEN current_revision_id ELSE NULL END,
            published_at=CASE WHEN operation='publish' THEN clock_timestamp() ELSE NULL END,
            lock_version=lock_version+1,updated_at=clock_timestamp()
            WHERE site_id=scope AND id=item.id RETURNING * INTO item;
        INSERT INTO axiom_content.outbox(site_id,article_id,revision_id,event)
            VALUES(scope,item.id,event_revision,
                CASE WHEN operation='publish' THEN 'content.published.v1' ELSE 'content.unpublished.v1' END);
    END IF;
    result := jsonb_build_object('id',item.id::text,'site_id',scope::text,'revision',item.current_revision_id,
        'version',item.lock_version::text,'status',item.status,'published_revision',item.published_revision_id);
    INSERT INTO axiom_content.command_receipts(site_id,user_id,key,digest,article_id,result)
        VALUES(scope,actor,command_key,fingerprint,item.id,result);
    RETURN result;
END;
$command$;
REVOKE ALL ON FUNCTION axiom_content.command(text,bigint,text,bigint,text,bigint,text,text,text,text) FROM PUBLIC;
GRANT USAGE ON SCHEMA axiom_content TO axiom_authenticator;
GRANT EXECUTE ON FUNCTION axiom_content.command(text,bigint,text,bigint,text,bigint,text,text,text,text)
    TO axiom_authenticator;
