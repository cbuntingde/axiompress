-- Filtered article queries and workspace overview for the native publishing workspace.
-- Extends the immutable platform command: article listing accepts status and title
-- filters, rows carry their revision taxonomy snapshot, and a bounded overview read
-- reports per-status counts without exposing content.
CREATE OR REPLACE FUNCTION axiom_platform.command(token text,configured_site bigint,operation text,params jsonb,command_key text)
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
        IF operation='articles.list' THEN
            IF keys<>ARRAY['cursor','q','status'] OR params->>'cursor' !~ '^(|[1-9][0-9]{0,17})$'
                OR params->>'status' NOT IN ('','draft','published','scheduled')
                OR octet_length(params->>'q')>64 THEN
                RETURN jsonb_build_object('error','invalid_request'); END IF;
            cursor:=coalesce(nullif(params->>'cursor','')::bigint,0);
            SELECT coalesce(jsonb_agg(row),'[]'::jsonb) INTO result FROM (
                SELECT a.id::text,a.site_id::text,a.status,a.lock_version::text AS version,r.title,a.updated_at,
                    s.due_at AS scheduled_at,s.status AS schedule_status,
                    coalesce((SELECT jsonb_agg(jsonb_build_object('kind',t.kind,'name',t.name)
                        ORDER BY t.kind,t.name)
                        FROM (SELECT kind,name FROM axiom_platform.revision_terms
                            WHERE site_id=a.site_id AND article_id=a.id AND revision_id=a.current_revision_id
                            ORDER BY kind,name LIMIT 8) t),'[]'::jsonb) AS terms
                FROM axiom_content.articles a JOIN axiom_content.revisions r
                    ON r.site_id=a.site_id AND r.article_id=a.id AND r.revision_id=a.current_revision_id
                LEFT JOIN axiom_jobs.schedules s ON s.site_id=a.site_id AND s.article_id=a.id
                WHERE a.site_id=configured_site
                    AND (principal.role IN ('owner','editor') OR a.author_id=principal.user_id)
                    AND (cursor=0 OR a.id<cursor)
                    AND (params->>'status'=''
                        OR (params->>'status'='scheduled' AND a.status='draft' AND s.status='pending')
                        OR (params->>'status' IN ('draft','published') AND a.status=params->>'status'))
                    AND (params->>'q'='' OR POSITION(lower(params->>'q') IN lower(r.title))>0)
                ORDER BY a.id DESC LIMIT 25
            ) row;
        ELSE
            IF keys<>ARRAY['cursor'] OR params->>'cursor' !~ '^(|[1-9][0-9]{0,17})$' THEN
                RETURN jsonb_build_object('error','invalid_request'); END IF;
            cursor:=coalesce(nullif(params->>'cursor','')::bigint,0);
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
    ELSIF operation='overview.read' THEN
        IF keys<>ARRAY[]::text[] THEN RETURN jsonb_build_object('error','invalid_request'); END IF;
        SELECT jsonb_build_object('site_id',configured_site::text,
            'draft',(SELECT count(*) FROM axiom_content.articles a
                WHERE a.site_id=configured_site AND a.status='draft'
                AND (principal.role IN ('owner','editor') OR a.author_id=principal.user_id)),
            'published',(SELECT count(*) FROM axiom_content.articles a
                WHERE a.site_id=configured_site AND a.status='published'
                AND (principal.role IN ('owner','editor') OR a.author_id=principal.user_id)),
            'scheduled',(SELECT count(*) FROM axiom_content.articles a JOIN axiom_jobs.schedules s
                ON s.site_id=a.site_id AND s.article_id=a.id
                WHERE a.site_id=configured_site AND a.status='draft' AND s.status='pending'
                AND (principal.role IN ('owner','editor') OR a.author_id=principal.user_id)),
            'media',(SELECT count(*) FROM axiom_media.assets m WHERE m.site_id=configured_site
                AND (principal.role IN ('owner','editor') OR m.creator=principal.user_id)),
            'terms',(SELECT count(*) FROM axiom_platform.terms t
                WHERE t.site_id=configured_site AND NOT t.archived))
        INTO result;
        RETURN result;
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

GRANT EXECUTE ON FUNCTION axiom_platform.command(text,bigint,text,jsonb,text) TO axiom_authenticator;
