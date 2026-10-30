CREATE SCHEMA axiom_media AUTHORIZATION axiom_owner;
REVOKE ALL ON SCHEMA axiom_media FROM PUBLIC;
CREATE TABLE axiom_auth.receipts(
    site_id bigint NOT NULL REFERENCES axiom_identity.sites(id),actor bigint NOT NULL REFERENCES axiom_identity.users(id),
    key text NOT NULL,digest text NOT NULL,result jsonb NOT NULL,created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY(site_id,actor,key)
);
CREATE TABLE axiom_media.assets(
    id text PRIMARY KEY DEFAULT replace(gen_random_uuid()::text,'-',''),
    site_id bigint NOT NULL REFERENCES axiom_identity.sites(id),creator bigint NOT NULL REFERENCES axiom_identity.users(id),
    name text NOT NULL CHECK(octet_length(name) BETWEEN 1 AND 255),
    source_mime text NOT NULL CHECK(source_mime IN ('image/png','image/jpeg','image/webp')),
    source_digest text NOT NULL,source bytea,rendered bytea,
    status text NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','processing','ready','failed')),
    public boolean NOT NULL DEFAULT false,version bigint NOT NULL DEFAULT 1 CHECK(version>0),
    width integer,height integer,attempts integer NOT NULL DEFAULT 0 CHECK(attempts BETWEEN 0 AND 3),
    lease_token text,lease_until timestamptz,available_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CHECK(source IS NULL OR octet_length(source)<=2097152),
    CHECK(rendered IS NULL OR octet_length(rendered)<=2097152),
    CHECK((status='processing')=(lease_token IS NOT NULL AND lease_until IS NOT NULL)),
    CHECK(NOT public OR status='ready')
);
CREATE INDEX media_by_site ON axiom_media.assets(site_id,created_at,id);

CREATE FUNCTION axiom_media.command(token text,configured_site bigint,operation text,target text,params jsonb,command_key text)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,axiom_media,pg_temp AS $command$
DECLARE principal record; asset axiom_media.assets%ROWTYPE; keys text[]; source bytea; digest text;
    receipt axiom_auth.receipts%ROWTYPE; result jsonb; wanted bigint;
BEGIN
    SELECT * INTO principal FROM axiom_auth.actor(token,configured_site);
    IF NOT FOUND THEN RETURN jsonb_build_object('error','unauthenticated'); END IF;
    IF operation IS NULL OR operation NOT IN ('create','read','file','publish','withdraw')
        OR jsonb_typeof(params) IS DISTINCT FROM 'object' THEN RETURN jsonb_build_object('error','invalid_request'); END IF;
    SELECT coalesce(array_agg(key ORDER BY key),ARRAY[]::text[]) INTO keys FROM jsonb_object_keys(params) key;
    IF EXISTS(SELECT FROM jsonb_each(params) WHERE jsonb_typeof(value)<>'string') THEN
        RETURN jsonb_build_object('error','invalid_request'); END IF;
    IF operation<>'create' THEN
        SELECT * INTO asset FROM axiom_media.assets WHERE site_id=configured_site AND id=target FOR UPDATE;
        IF NOT FOUND OR (principal.role='author' AND asset.creator<>principal.user_id) THEN
            RETURN jsonb_build_object('error','not_found'); END IF;
    END IF;
    IF operation IN ('read','file') THEN
        IF keys<>ARRAY[]::text[] THEN RETURN jsonb_build_object('error','invalid_request'); END IF;
        IF operation='file' THEN
            IF asset.status<>'ready' THEN RETURN jsonb_build_object('error','not_found'); END IF;
            RETURN jsonb_build_object('site_id',configured_site::text,'data',encode(asset.rendered,'base64'));
        END IF;
        RETURN jsonb_build_object('id',asset.id,'site_id',asset.site_id::text,'name',asset.name,'status',asset.status,
            'version',asset.version::text,'public',asset.public,'width',asset.width,'height',asset.height);
    END IF;
    IF operation='publish' AND principal.role NOT IN ('owner','editor') THEN
        RETURN jsonb_build_object('error','forbidden'); END IF;
    IF command_key IS NULL OR command_key !~ '^[a-f0-9]{64}$' OR octet_length(command_key)<>64 THEN
        RETURN jsonb_build_object('error','invalid_request'); END IF;
    IF (operation='create' AND keys<>ARRAY['data','mime','name'])
        OR (operation<>'create' AND keys<>ARRAY['expected_version']) THEN
        RETURN jsonb_build_object('error','invalid_request'); END IF;
    IF operation='create' THEN
        IF params->>'mime' NOT IN ('image/png','image/jpeg','image/webp')
            OR octet_length(params->>'name') NOT BETWEEN 1 AND 255
            OR octet_length(params->>'data') NOT BETWEEN 12 AND 2796204
            OR params->>'data' !~ '^[A-Za-z0-9+/]*={0,2}$' THEN
            RETURN jsonb_build_object('error','invalid_request'); END IF;
        source:=decode(params->>'data','base64');
        IF octet_length(source)>2097152 OR replace(encode(source,'base64'),E'\n','')<>params->>'data' THEN
            RETURN jsonb_build_object('error','invalid_request'); END IF;
        IF NOT ((params->>'mime'='image/png' AND substring(source FROM 1 FOR 8)=decode('89504e470d0a1a0a','hex'))
            OR (params->>'mime'='image/jpeg' AND substring(source FROM 1 FOR 3)=decode('ffd8ff','hex'))
            OR (params->>'mime'='image/webp' AND substring(source FROM 1 FOR 4)=convert_to('RIFF','UTF8')
                AND substring(source FROM 9 FOR 4)=convert_to('WEBP','UTF8'))) THEN
            RETURN jsonb_build_object('error','invalid_request'); END IF;
    ELSE
        IF params->>'expected_version' !~ '^[1-9][0-9]{0,17}$' THEN
            RETURN jsonb_build_object('error','invalid_request'); END IF;
        wanted:=(params->>'expected_version')::bigint;
    END IF;
    digest:=encode(sha256(convert_to(jsonb_build_array('media',operation,target,params)::text,'UTF8')),'hex');
    DELETE FROM axiom_auth.receipts WHERE site_id=configured_site AND created_at<=clock_timestamp()-interval '14 days';
    SELECT * INTO receipt FROM axiom_auth.receipts r
        WHERE r.site_id=configured_site AND r.actor=principal.user_id AND r.key=command_key;
    IF FOUND THEN
        IF receipt.digest<>digest OR receipt.created_at<=clock_timestamp()-interval '7 days' THEN
            RETURN jsonb_build_object('error','conflict'); END IF;
        RETURN receipt.result;
    END IF;
    IF (SELECT count(*) FROM axiom_auth.receipts WHERE site_id=configured_site)>=4096 THEN
        RETURN jsonb_build_object('error','quota_exceeded'); END IF;
    IF operation='create' THEN
        IF (SELECT count(*) FROM axiom_media.assets WHERE site_id=configured_site)>=100 THEN
            RETURN jsonb_build_object('error','quota_exceeded'); END IF;
        INSERT INTO axiom_media.assets(site_id,creator,name,source_mime,source_digest,source)
            VALUES(configured_site,principal.user_id,params->>'name',params->>'mime',encode(sha256(source),'hex'),source)
            RETURNING * INTO asset;
    ELSE
        IF asset.version<>wanted OR asset.status<>'ready' OR asset.version>=999999999999999999 THEN
            RETURN jsonb_build_object('error','conflict'); END IF;
        UPDATE axiom_media.assets SET public=(operation='publish'),version=version+1
            WHERE id=asset.id RETURNING * INTO asset;
    END IF;
    result:=jsonb_build_object('id',asset.id,'site_id',asset.site_id::text,'status',asset.status,
        'version',asset.version::text,'public',asset.public);
    INSERT INTO axiom_auth.receipts(site_id,actor,key,digest,result)
        VALUES(configured_site,principal.user_id,command_key,digest,result);
    RETURN result;
END;
$command$;

CREATE FUNCTION axiom_media.claim() RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER
SET search_path=pg_catalog,axiom_media,pg_temp AS $claim$
DECLARE scope bigint:=axiom_jobs.site(); asset axiom_media.assets%ROWTYPE;
BEGIN
    IF scope IS NULL THEN RAISE EXCEPTION 'Invalid processor scope' USING ERRCODE='42501'; END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended('axiom-auth:'||scope,0));
    UPDATE axiom_media.assets SET status=CASE WHEN attempts>=3 THEN 'failed' ELSE 'pending' END,
        source=CASE WHEN attempts>=3 THEN NULL ELSE source END,lease_token=NULL,lease_until=NULL,
        available_at=clock_timestamp()+interval '10 seconds'
        WHERE site_id=scope AND status='processing' AND lease_until<=clock_timestamp();
    SELECT * INTO asset FROM axiom_media.assets WHERE site_id=scope AND status='pending'
        AND available_at<=clock_timestamp() ORDER BY created_at,id LIMIT 1 FOR UPDATE;
    IF NOT FOUND THEN RETURN '{}'::jsonb; END IF;
    UPDATE axiom_media.assets SET status='processing',attempts=attempts+1,
        lease_token=replace(gen_random_uuid()::text,'-',''),lease_until=clock_timestamp()+interval '30 seconds'
        WHERE id=asset.id RETURNING * INTO asset;
    RETURN jsonb_build_object('id',asset.id,'site_id',scope::text,'lease',asset.lease_token,'data',encode(asset.source,'base64'));
END;
$claim$;
CREATE FUNCTION axiom_media.finish(target text,handle text,outcome text,png text,w integer,h integer)
RETURNS jsonb LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,axiom_media,pg_temp AS $finish$
DECLARE scope bigint:=axiom_jobs.site(); asset axiom_media.assets%ROWTYPE; png_bytes bytea;
BEGIN
    IF scope IS NULL THEN RAISE EXCEPTION 'Invalid processor scope' USING ERRCODE='42501'; END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended('axiom-auth:'||scope,0));
    SELECT * INTO asset FROM axiom_media.assets WHERE id=target AND site_id=scope AND status='processing'
        AND lease_token=handle AND lease_until>clock_timestamp() FOR UPDATE;
    IF NOT FOUND THEN RETURN jsonb_build_object('error','conflict'); END IF;
    IF outcome='ready' THEN
        IF png IS NULL OR octet_length(png)>2796204 OR w IS NULL OR h IS NULL OR w NOT BETWEEN 1 AND 1600
            OR h NOT BETWEEN 1 AND 1600 THEN RETURN jsonb_build_object('error','invalid_request'); END IF;
        png_bytes:=decode(png,'base64');
        IF octet_length(png_bytes)>2097152 OR substring(png_bytes FROM 1 FOR 8)<>decode('89504e470d0a1a0a','hex') THEN
            RETURN jsonb_build_object('error','invalid_request'); END IF;
        UPDATE axiom_media.assets SET status='ready',rendered=png_bytes,width=w,height=h,source=NULL,
            lease_token=NULL,lease_until=NULL,version=version+1 WHERE id=asset.id;
    ELSIF outcome IN ('invalid','retry') THEN
        UPDATE axiom_media.assets SET status=CASE WHEN outcome='invalid' OR attempts>=3 THEN 'failed' ELSE 'pending' END,
            source=CASE WHEN outcome='invalid' OR attempts>=3 THEN NULL ELSE source END,
            lease_token=NULL,lease_until=NULL,available_at=clock_timestamp()+interval '10 seconds' WHERE id=asset.id;
    ELSE RETURN jsonb_build_object('error','invalid_request'); END IF;
    RETURN jsonb_build_object('outcome',outcome,'site_id',scope::text);
END;
$finish$;
CREATE FUNCTION axiom_media.public_file(configured_site bigint,target text) RETURNS text
LANGUAGE sql STABLE SECURITY DEFINER SET search_path=pg_catalog,axiom_media,pg_temp AS $public$
    SELECT encode(rendered,'base64') FROM axiom_media.assets WHERE id=target AND site_id=configured_site
        AND status='ready' AND public AND axiom_access.reader_can_access(configured_site)
$public$;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA axiom_media FROM PUBLIC;
GRANT USAGE ON SCHEMA axiom_media TO axiom_reader,axiom_authenticator,axiom_job_runner;
GRANT EXECUTE ON FUNCTION axiom_media.public_file(bigint,text) TO axiom_reader;
GRANT EXECUTE ON FUNCTION axiom_media.command(text,bigint,text,text,jsonb,text) TO axiom_authenticator;
GRANT EXECUTE ON FUNCTION axiom_media.claim(),axiom_media.finish(text,text,text,text,integer,integer) TO axiom_job_runner;
