CREATE SCHEMA axiom_platform AUTHORIZATION axiom_owner;
CREATE SCHEMA axiom_audit AUTHORIZATION axiom_owner;
REVOKE ALL ON SCHEMA axiom_platform,axiom_audit FROM PUBLIC;
CREATE TABLE axiom_platform.settings(
    site_id bigint PRIMARY KEY REFERENCES axiom_identity.sites(id),version bigint NOT NULL DEFAULT 1 CHECK(version>0),
    title text NOT NULL CHECK(octet_length(title) BETWEEN 1 AND 256),
    tagline text NOT NULL DEFAULT '' CHECK(octet_length(tagline)<=512),
    theme text NOT NULL DEFAULT 'paper' CHECK(theme IN ('paper','ink')),
    page_size integer NOT NULL DEFAULT 12 CHECK(page_size BETWEEN 1 AND 25)
);
CREATE TABLE axiom_platform.terms(
    id text PRIMARY KEY DEFAULT replace(gen_random_uuid()::text,'-',''),
    site_id bigint NOT NULL REFERENCES axiom_identity.sites(id),
    kind text NOT NULL CHECK(kind IN ('category','tag')),
    name text NOT NULL CHECK(octet_length(name) BETWEEN 1 AND 128),
    slug text NOT NULL CHECK(slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$' AND octet_length(slug)<=63),
    version bigint NOT NULL DEFAULT 1 CHECK(version>0),archived boolean NOT NULL DEFAULT false,
    UNIQUE(site_id,kind,slug),UNIQUE(site_id,id)
);
CREATE TABLE axiom_platform.revision_terms(
    site_id bigint NOT NULL,article_id bigint NOT NULL,revision_id text NOT NULL,term_id text NOT NULL,
    name text NOT NULL,slug text NOT NULL,kind text NOT NULL,
    PRIMARY KEY(site_id,article_id,revision_id,term_id),
    FOREIGN KEY(site_id,article_id,revision_id) REFERENCES axiom_content.revisions(site_id,article_id,revision_id),
    FOREIGN KEY(site_id,term_id) REFERENCES axiom_platform.terms(site_id,id)
);
ALTER TABLE axiom_media.assets ADD CONSTRAINT media_site_identity UNIQUE(site_id,id);
CREATE TABLE axiom_platform.revision_media(
    site_id bigint NOT NULL,article_id bigint NOT NULL,revision_id text NOT NULL,media_id text NOT NULL,
    PRIMARY KEY(site_id,article_id,revision_id,media_id),
    FOREIGN KEY(site_id,article_id,revision_id) REFERENCES axiom_content.revisions(site_id,article_id,revision_id),
    FOREIGN KEY(site_id,media_id) REFERENCES axiom_media.assets(site_id,id)
);
CREATE TABLE axiom_audit.events(
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,site_id bigint NOT NULL REFERENCES axiom_identity.sites(id),
    occurred_at timestamptz NOT NULL,document jsonb NOT NULL CHECK(octet_length(document::text)<=4096),
    previous_hash text NOT NULL,hash text NOT NULL
);
CREATE INDEX audit_by_site ON axiom_audit.events(site_id,id);
CREATE TABLE axiom_audit.anchors(site_id bigint PRIMARY KEY,last_id bigint NOT NULL,last_hash text NOT NULL);
CREATE FUNCTION axiom_audit.append(scope bigint,actor bigint,action text,resource text,outcome text) RETURNS void
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,axiom_audit,pg_temp SET timezone='UTC' AS $audit$
DECLARE previous text; document jsonb; instant timestamptz:=clock_timestamp();
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended('axiom-auth:'||scope,0));
    IF octet_length(action)>64 OR octet_length(resource)>128 OR octet_length(outcome)>32
        OR (SELECT count(*) FROM axiom_audit.events WHERE site_id=scope)>=10000 THEN
        RAISE EXCEPTION 'Audit capacity or metadata limit' USING ERRCODE='54000'; END IF;
    SELECT hash INTO previous FROM axiom_audit.events WHERE site_id=scope ORDER BY id DESC LIMIT 1;
    IF previous IS NULL THEN SELECT last_hash INTO previous FROM axiom_audit.anchors WHERE site_id=scope; END IF;
    previous:=coalesce(previous,repeat('0',64));
    document:=jsonb_build_object('site_id',scope::text,'actor',actor::text,'database_identity',session_user,
        'action',action,'resource',resource,'outcome',outcome,
        'time',to_char(instant,'YYYY-MM-DD"T"HH24:MI:SS.US"Z"'));
    INSERT INTO axiom_audit.events(site_id,occurred_at,document,previous_hash,hash)
        VALUES(scope,instant,document,previous,encode(sha256(convert_to(previous||document::text,'UTF8')),'hex'));
END;
$audit$;
REVOKE ALL ON FUNCTION axiom_audit.append(bigint,bigint,text,text,text) FROM PUBLIC,axiom_authenticator,axiom_job_runner;

-- Preserve immutable migration 0003 while adding atomic taxonomy inheritance and audit.
ALTER FUNCTION axiom_content.command(text,bigint,text,bigint,text,bigint,text,text,text,text) RENAME TO command_v1;
REVOKE ALL ON FUNCTION axiom_content.command_v1(text,bigint,text,bigint,text,bigint,text,text,text,text)
    FROM PUBLIC,axiom_authenticator;
CREATE FUNCTION axiom_content.command(session_token text,configured_site bigint,operation text,target bigint,
    expected_revision text,expected_version bigint,title_text text,body_text text,command_key text,new_revision text)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,axiom_content,pg_temp AS $content$
DECLARE principal record; prior_revision text; result jsonb; media_ids text[]; replay boolean;
BEGIN
    SELECT * INTO principal FROM axiom_auth.actor(session_token,configured_site);
    IF NOT FOUND THEN RETURN jsonb_build_object('error','unauthenticated'); END IF;
    SELECT EXISTS(SELECT FROM axiom_content.command_receipts WHERE site_id=configured_site
        AND user_id=principal.user_id AND key=command_key) INTO replay;
    SELECT current_revision_id INTO prior_revision FROM axiom_content.articles
        WHERE site_id=configured_site AND id=target FOR UPDATE;
    IF operation IN ('create','edit') AND left(body_text,8)='<!-- wp:' THEN
        SELECT coalesce(array_agg(DISTINCT match[1]),ARRAY[]::text[]) INTO media_ids
            FROM regexp_matches(body_text,'<!-- wp:axiom/image \{"media":"([a-f0-9]{32})","alt":','g') match;
        IF (SELECT count(*) FROM axiom_media.assets a WHERE a.site_id=configured_site AND a.id=ANY(media_ids)
            AND (principal.role IN ('owner','editor') OR a.creator=principal.user_id OR a.public))<>cardinality(media_ids) THEN
            RETURN jsonb_build_object('error','not_found'); END IF;
        PERFORM 1 FROM axiom_media.assets WHERE site_id=configured_site AND id=ANY(media_ids) FOR SHARE;
    END IF;
    IF operation='publish' AND EXISTS(SELECT FROM axiom_platform.revision_media r JOIN axiom_media.assets a
        ON a.site_id=r.site_id AND a.id=r.media_id WHERE r.site_id=configured_site AND r.article_id=target
        AND r.revision_id=prior_revision AND (a.status<>'ready' OR NOT a.public)) THEN
        RETURN jsonb_build_object('error','conflict'); END IF;
    result:=axiom_content.command_v1(session_token,configured_site,operation,target,expected_revision,expected_version,
        title_text,body_text,command_key,new_revision);
    IF operation='edit' AND result ? 'id' AND result->>'revision'=new_revision THEN
        INSERT INTO axiom_platform.revision_terms SELECT site_id,article_id,new_revision,term_id,name,slug,kind
            FROM axiom_platform.revision_terms WHERE site_id=configured_site AND article_id=target
            AND revision_id=prior_revision ON CONFLICT DO NOTHING;
    END IF;
    IF operation IN ('create','edit') AND result ? 'id' AND result->>'revision'=new_revision THEN
        INSERT INTO axiom_platform.revision_media SELECT configured_site,(result->>'id')::bigint,new_revision,id
            FROM axiom_media.assets WHERE site_id=configured_site AND id=ANY(coalesce(media_ids,ARRAY[]::text[]))
            ON CONFLICT DO NOTHING;
    END IF;
    IF operation='read' AND result ? 'id' THEN
        result:=result||jsonb_build_object('terms',(SELECT coalesce(jsonb_agg(term_id),'[]'::jsonb)
            FROM axiom_platform.revision_terms WHERE site_id=configured_site AND article_id=target
            AND revision_id=result->>'revision'),'scheduled_at',(SELECT due_at FROM axiom_jobs.schedules
            WHERE site_id=configured_site AND article_id=target AND status='pending'));
    END IF;
    IF operation<>'read' AND NOT replay THEN
        PERFORM axiom_audit.append(configured_site,principal.user_id,'article.'||operation,
            coalesce(result->>'id',target::text),coalesce(result->>'error','success'));
    END IF;
    RETURN result;
END;
$content$;
REVOKE ALL ON FUNCTION axiom_content.command(text,bigint,text,bigint,text,bigint,text,text,text,text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION axiom_content.command(text,bigint,text,bigint,text,bigint,text,text,text,text)
    TO axiom_authenticator;

CREATE FUNCTION axiom_platform.command(token text,configured_site bigint,operation text,params jsonb,command_key text)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,axiom_platform,pg_temp AS $platform$
DECLARE principal record; keys text[]; result jsonb; digest text; receipt axiom_auth.receipts%ROWTYPE;
    item axiom_content.articles%ROWTYPE; setting axiom_platform.settings%ROWTYPE; term axiom_platform.terms%ROWTYPE;
    target bigint; cursor bigint; requested_terms jsonb; revision text; revision_version bigint;
BEGIN
    SELECT * INTO principal FROM axiom_auth.actor(token,configured_site);
    IF NOT FOUND THEN RETURN jsonb_build_object('error','unauthenticated'); END IF;
    IF operation IS NULL OR jsonb_typeof(params) IS DISTINCT FROM 'object'
        OR EXISTS(SELECT FROM jsonb_each(params) WHERE jsonb_typeof(value)<>'string') THEN
        RETURN jsonb_build_object('error','invalid_request'); END IF;
    SELECT coalesce(array_agg(key ORDER BY key),ARRAY[]::text[]) INTO keys FROM jsonb_object_keys(params) key;
    IF operation IN ('articles.list','media.list') THEN
        IF keys<>ARRAY['cursor'] OR params->>'cursor' !~ '^(|[1-9][0-9]{0,17})$' THEN
            RETURN jsonb_build_object('error','invalid_request'); END IF;
        cursor:=coalesce(nullif(params->>'cursor','')::bigint,0);
        IF operation='articles.list' THEN
            SELECT coalesce(jsonb_agg(row),'[]'::jsonb) INTO result FROM (
                SELECT a.id::text,a.site_id::text,a.status,a.lock_version::text AS version,r.title,a.updated_at,
                    s.due_at AS scheduled_at,s.status AS schedule_status
                FROM axiom_content.articles a JOIN axiom_content.revisions r
                    ON r.site_id=a.site_id AND r.article_id=a.id AND r.revision_id=a.current_revision_id
                LEFT JOIN axiom_jobs.schedules s ON s.site_id=a.site_id AND s.article_id=a.id
                WHERE a.site_id=configured_site AND (principal.role IN ('owner','editor') OR a.author_id=principal.user_id)
                AND (cursor=0 OR a.id<cursor) ORDER BY a.id DESC LIMIT 25
            ) row;
        ELSE
            SELECT coalesce(jsonb_agg(row),'[]'::jsonb) INTO result FROM (
                SELECT id,site_id::text,name,status,version::text,public,width,height,created_at
                FROM axiom_media.assets WHERE site_id=configured_site
                AND (principal.role IN ('owner','editor') OR creator=principal.user_id)
                ORDER BY created_at DESC,id LIMIT 100
            ) row;
        END IF;
        RETURN jsonb_build_object('site_id',configured_site::text,'items',result);
    ELSIF operation='revisions.list' THEN
        IF keys<>ARRAY['id'] OR params->>'id' !~ '^[1-9][0-9]{0,17}$' THEN
            RETURN jsonb_build_object('error','invalid_request'); END IF;
        target:=(params->>'id')::bigint;
        SELECT * INTO item FROM axiom_content.articles WHERE site_id=configured_site AND id=target;
        IF NOT FOUND OR (principal.role='author' AND item.author_id<>principal.user_id) THEN
            RETURN jsonb_build_object('error','not_found'); END IF;
        SELECT coalesce(jsonb_agg(row),'[]'::jsonb) INTO result FROM (
            SELECT revision_id,version::text,title,created_at,created_by::text
            FROM axiom_content.revisions WHERE site_id=configured_site AND article_id=target
            ORDER BY version DESC LIMIT 100
        ) row;
        RETURN jsonb_build_object('site_id',configured_site::text,'items',result);
    ELSIF operation IN ('settings.read','terms.list') THEN
        IF keys<>ARRAY[]::text[] THEN RETURN jsonb_build_object('error','invalid_request'); END IF;
        IF operation='settings.read' THEN
            SELECT jsonb_build_object('site_id',configured_site::text,'version',coalesce(s.version,1)::text,
                'title',coalesce(s.title,i.name),'tagline',coalesce(s.tagline,''),
                'theme',coalesce(s.theme,'paper'),'page_size',coalesce(s.page_size,12))
                INTO result FROM axiom_identity.sites i LEFT JOIN axiom_platform.settings s ON s.site_id=i.id
                WHERE i.id=configured_site;
            RETURN result;
        END IF;
        SELECT coalesce(jsonb_agg(row),'[]'::jsonb) INTO result FROM (
            SELECT id,kind,name,slug,version::text,archived FROM axiom_platform.terms
            WHERE site_id=configured_site ORDER BY kind,name,id LIMIT 256
        ) row;
        RETURN jsonb_build_object('site_id',configured_site::text,'items',result);
    END IF;

    IF operation IN ('relationships.write','articles.restore') THEN
        IF keys<>(CASE WHEN operation='relationships.write' THEN ARRAY['expected_revision','expected_version','id','terms']
            ELSE ARRAY['expected_revision','expected_version','id','revision'] END)
            OR params->>'id' !~ '^[1-9][0-9]{0,17}$'
            OR params->>'expected_version' !~ '^[1-9][0-9]{0,17}$'
            OR params->>'expected_revision' !~ '^[a-f0-9]{64}$' OR octet_length(params->>'terms')>1024
            OR (operation='articles.restore' AND params->>'revision' !~ '^[a-f0-9]{64}$') THEN
            RETURN jsonb_build_object('error','invalid_request'); END IF;
        target:=(params->>'id')::bigint;
        SELECT * INTO item FROM axiom_content.articles WHERE site_id=configured_site AND id=target FOR UPDATE;
        IF NOT FOUND OR (principal.role='author' AND item.author_id<>principal.user_id) THEN
            RETURN jsonb_build_object('error','not_found'); END IF;
    ELSIF operation='settings.write' THEN
        IF principal.role<>'owner' THEN RETURN jsonb_build_object('error','forbidden'); END IF;
        IF keys<>ARRAY['expected_version','page_size','tagline','theme','title']
            OR params->>'expected_version' !~ '^[1-9][0-9]{0,17}$'
            OR params->>'page_size' !~ '^([1-9]|1[0-9]|2[0-5])$'
            OR params->>'theme' NOT IN ('paper','ink') OR octet_length(params->>'title') NOT BETWEEN 1 AND 256
            OR octet_length(params->>'tagline')>512 THEN RETURN jsonb_build_object('error','invalid_request'); END IF;
    ELSIF operation='terms.archive' THEN
        IF principal.role NOT IN ('owner','editor') THEN RETURN jsonb_build_object('error','forbidden'); END IF;
        IF keys<>ARRAY['expected_version','id'] OR params->>'id' !~ '^[a-f0-9]{32}$'
            OR params->>'expected_version' !~ '^[1-9][0-9]{0,17}$' THEN
            RETURN jsonb_build_object('error','invalid_request'); END IF;
    ELSIF operation='terms.write' THEN
        IF principal.role NOT IN ('owner','editor') THEN RETURN jsonb_build_object('error','forbidden'); END IF;
        IF keys<>ARRAY['expected_version','id','kind','name','slug'] OR params->>'kind' NOT IN ('category','tag')
            OR octet_length(params->>'name') NOT BETWEEN 1 AND 128 OR octet_length(params->>'slug')>63
            OR params->>'slug' !~ '^[a-z0-9]+(-[a-z0-9]+)*$'
            OR params->>'id' !~ '^(|[a-f0-9]{32})$' OR params->>'expected_version' !~ '^(0|[1-9][0-9]{0,17})$' THEN
            RETURN jsonb_build_object('error','invalid_request'); END IF;
    ELSE RETURN jsonb_build_object('error','invalid_request'); END IF;
    IF command_key IS NULL OR command_key !~ '^[a-f0-9]{64}$' OR octet_length(command_key)<>64 THEN
        RETURN jsonb_build_object('error','invalid_request'); END IF;
    digest:=encode(sha256(convert_to(jsonb_build_array(operation,params)::text,'UTF8')),'hex');
    DELETE FROM axiom_auth.receipts WHERE site_id=configured_site AND created_at<=clock_timestamp()-interval '14 days';
    SELECT * INTO receipt FROM axiom_auth.receipts r WHERE r.site_id=configured_site
        AND r.actor=principal.user_id AND r.key=command_key;
    IF FOUND THEN
        IF receipt.digest<>digest OR receipt.created_at<=clock_timestamp()-interval '7 days' THEN
            RETURN jsonb_build_object('error','conflict'); END IF;
        RETURN receipt.result;
    END IF;
    IF (SELECT count(*) FROM axiom_auth.receipts WHERE site_id=configured_site)>=4096 THEN
        RETURN jsonb_build_object('error','quota_exceeded'); END IF;

    IF operation='settings.write' THEN
        INSERT INTO axiom_platform.settings(site_id,title) SELECT id,name FROM axiom_identity.sites
            WHERE id=configured_site ON CONFLICT DO NOTHING;
        SELECT * INTO setting FROM axiom_platform.settings WHERE site_id=configured_site FOR UPDATE;
        IF setting.version<>(params->>'expected_version')::bigint OR setting.version>=999999999999999999 THEN
            RETURN jsonb_build_object('error','conflict'); END IF;
        UPDATE axiom_platform.settings SET title=params->>'title',tagline=params->>'tagline',theme=params->>'theme',
            page_size=(params->>'page_size')::integer,version=version+1 WHERE site_id=configured_site RETURNING * INTO setting;
        result:=jsonb_build_object('site_id',configured_site::text,'version',setting.version::text);
    ELSIF operation='terms.archive' THEN
        SELECT * INTO term FROM axiom_platform.terms WHERE site_id=configured_site AND id=params->>'id' FOR UPDATE;
        IF NOT FOUND THEN RETURN jsonb_build_object('error','not_found'); END IF;
        IF term.version<>(params->>'expected_version')::bigint OR term.version>=999999999999999999 THEN
            RETURN jsonb_build_object('error','conflict'); END IF;
        UPDATE axiom_platform.terms SET archived=true,version=version+1 WHERE id=term.id RETURNING * INTO term;
        result:=jsonb_build_object('site_id',configured_site::text,'id',term.id,'version',term.version::text);
    ELSIF operation='terms.write' THEN
        IF params->>'id'='' THEN
            IF params->>'expected_version'<>'0' THEN RETURN jsonb_build_object('error','conflict'); END IF;
            IF (SELECT count(*) FROM axiom_platform.terms WHERE site_id=configured_site)>=256
                THEN RETURN jsonb_build_object('error','quota_exceeded'); END IF;
            IF EXISTS(SELECT FROM axiom_platform.terms t WHERE t.site_id=configured_site
                AND t.slug=params->>'slug' AND t.kind=params->>'kind') THEN RETURN jsonb_build_object('error','conflict'); END IF;
            INSERT INTO axiom_platform.terms(site_id,kind,name,slug)
                VALUES(configured_site,params->>'kind',params->>'name',params->>'slug') RETURNING * INTO term;
        ELSE
            SELECT * INTO term FROM axiom_platform.terms WHERE site_id=configured_site AND id=params->>'id' FOR UPDATE;
            IF NOT FOUND THEN RETURN jsonb_build_object('error','not_found'); END IF;
            IF term.version<>(params->>'expected_version')::bigint OR term.version>=999999999999999999 THEN
                RETURN jsonb_build_object('error','conflict'); END IF;
            IF EXISTS(SELECT FROM axiom_platform.terms t WHERE t.site_id=configured_site AND t.id<>term.id
                AND t.slug=params->>'slug' AND t.kind=params->>'kind') THEN RETURN jsonb_build_object('error','conflict'); END IF;
            UPDATE axiom_platform.terms SET kind=params->>'kind',name=params->>'name',slug=params->>'slug',version=version+1,
                archived=false
                WHERE id=term.id RETURNING * INTO term;
        END IF;
        result:=jsonb_build_object('site_id',configured_site::text,'id',term.id,'version',term.version::text);
    ELSE
        IF operation='relationships.write' THEN requested_terms:=(params->>'terms')::jsonb;
        ELSE requested_terms:='[]'::jsonb; END IF;
        IF jsonb_typeof(requested_terms)<>'array' OR jsonb_array_length(requested_terms)>16 THEN
            RETURN jsonb_build_object('error','invalid_request'); END IF;
        IF (SELECT count(*) FROM axiom_platform.terms t WHERE t.site_id=configured_site AND NOT t.archived
            AND t.id IN (SELECT jsonb_array_elements_text(requested_terms)))<>jsonb_array_length(requested_terms) THEN
            RETURN jsonb_build_object('error','invalid_request'); END IF;
        IF item.lock_version<>(params->>'expected_version')::bigint OR item.current_revision_id<>params->>'expected_revision'
            OR item.lock_version>=999999999999999999 THEN RETURN jsonb_build_object('error','conflict'); END IF;
        SELECT max(version)+1 INTO revision_version FROM axiom_content.revisions
            WHERE site_id=configured_site AND article_id=target;
        IF revision_version>1000 THEN RETURN jsonb_build_object('error','quota_exceeded'); END IF;
        revision:=encode(sha256(convert_to(gen_random_uuid()::text,'UTF8')),'hex');
        IF operation='articles.restore' AND NOT EXISTS(SELECT FROM axiom_content.revisions r
            WHERE r.site_id=configured_site AND r.article_id=target AND r.revision_id=params->>'revision') THEN
            RETURN jsonb_build_object('error','not_found'); END IF;
        INSERT INTO axiom_content.revisions(site_id,article_id,revision_id,version,title,body,created_by)
            SELECT configured_site,target,revision,revision_version,r.title,r.body,principal.user_id
            FROM axiom_content.revisions r WHERE r.site_id=configured_site AND r.article_id=target
            AND r.revision_id=CASE WHEN operation='articles.restore' THEN params->>'revision' ELSE item.current_revision_id END;
        IF operation='articles.restore' THEN
            INSERT INTO axiom_platform.revision_terms SELECT site_id,article_id,revision,term_id,name,slug,kind
                FROM axiom_platform.revision_terms WHERE site_id=configured_site AND article_id=target
                AND revision_id=params->>'revision';
            INSERT INTO axiom_platform.revision_media SELECT site_id,article_id,revision,media_id
                FROM axiom_platform.revision_media WHERE site_id=configured_site AND article_id=target
                AND revision_id=params->>'revision';
        ELSE
            INSERT INTO axiom_platform.revision_terms SELECT configured_site,target,revision,t.id,t.name,t.slug,t.kind
                FROM axiom_platform.terms t WHERE t.site_id=configured_site
                AND t.id IN (SELECT jsonb_array_elements_text(requested_terms));
            INSERT INTO axiom_platform.revision_media SELECT site_id,article_id,revision,media_id
                FROM axiom_platform.revision_media WHERE site_id=configured_site AND article_id=target
                AND revision_id=item.current_revision_id;
        END IF;
        UPDATE axiom_content.articles SET current_revision_id=revision,lock_version=lock_version+1,updated_at=clock_timestamp()
            WHERE site_id=configured_site AND id=target RETURNING * INTO item;
        result:=jsonb_build_object('site_id',configured_site::text,'id',target::text,'revision',revision,
            'version',item.lock_version::text);
    END IF;
    INSERT INTO axiom_auth.receipts(site_id,actor,key,digest,result)
        VALUES(configured_site,principal.user_id,command_key,digest,result);
    PERFORM axiom_audit.append(configured_site,principal.user_id,operation,coalesce(result->>'id','site'),'success');
    RETURN result;
END;
$platform$;

CREATE FUNCTION axiom_platform.public_settings(configured_site bigint) RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER
SET search_path=pg_catalog,axiom_platform,pg_temp AS $settings$
    SELECT jsonb_build_object('title',coalesce(s.title,i.name),'tagline',coalesce(s.tagline,''),
        'theme',coalesce(s.theme,'paper'),'page_size',coalesce(s.page_size,12))
    FROM axiom_identity.sites i LEFT JOIN axiom_platform.settings s ON s.site_id=i.id
    WHERE i.id=configured_site AND axiom_access.reader_can_access(configured_site)
$settings$;
CREATE FUNCTION axiom_platform.public_terms(configured_site bigint,target bigint) RETURNS jsonb LANGUAGE sql STABLE SECURITY DEFINER
SET search_path=pg_catalog,axiom_platform,pg_temp AS $terms$
    SELECT coalesce(jsonb_agg(jsonb_build_object('kind',t.kind,'name',t.name,'slug',t.slug)),'[]'::jsonb)
    FROM axiom_content.articles a JOIN axiom_platform.revision_terms t
        ON t.site_id=a.site_id AND t.article_id=a.id AND t.revision_id=a.published_revision_id
    WHERE a.site_id=configured_site AND a.id=target AND a.status='published'
        AND a.published_at<=statement_timestamp() AND axiom_access.reader_can_access(configured_site)
$terms$;
CREATE FUNCTION axiom_platform.public_articles(configured_site bigint,after_id bigint) RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,axiom_platform,pg_temp AS $articles$
    SELECT coalesce(jsonb_agg(row),'[]'::jsonb) FROM (
        SELECT a.id::text,r.title,a.published_at FROM axiom_content.articles a JOIN axiom_content.revisions r
            ON r.site_id=a.site_id AND r.article_id=a.id AND r.revision_id=a.published_revision_id
        WHERE a.site_id=configured_site AND a.status='published' AND a.published_at<=statement_timestamp()
        AND (after_id=0 OR a.id<after_id) AND axiom_access.reader_can_access(configured_site)
        ORDER BY a.id DESC LIMIT coalesce((SELECT page_size FROM axiom_platform.settings WHERE site_id=configured_site),12)
    ) row
$articles$;
CREATE FUNCTION axiom_platform.public_media(configured_site bigint,target text) RETURNS jsonb
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,axiom_platform,pg_temp AS $media$
    SELECT jsonb_build_object('width',width,'height',height) FROM axiom_media.assets
        WHERE site_id=configured_site AND id=target AND public AND status='ready'
        AND axiom_access.reader_can_access(configured_site)
$media$;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA axiom_platform FROM PUBLIC;
GRANT USAGE ON SCHEMA axiom_platform TO axiom_authenticator,axiom_reader;
GRANT EXECUTE ON FUNCTION axiom_platform.command(text,bigint,text,jsonb,text) TO axiom_authenticator;
GRANT EXECUTE ON FUNCTION axiom_platform.public_settings(bigint),axiom_platform.public_terms(bigint,bigint),
    axiom_platform.public_articles(bigint,bigint) TO axiom_reader;
GRANT EXECUTE ON FUNCTION axiom_platform.public_media(bigint,text) TO axiom_reader;
