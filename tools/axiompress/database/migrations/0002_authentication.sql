ALTER TABLE axiom_identity.memberships ADD COLUMN disabled_at timestamptz;
CREATE SCHEMA axiom_auth AUTHORIZATION axiom_owner;
REVOKE ALL ON SCHEMA axiom_auth FROM PUBLIC;
CREATE TABLE axiom_auth.sites (
    database_role oid PRIMARY KEY,
    site_id bigint NOT NULL REFERENCES axiom_identity.sites(id)
);
CREATE TABLE axiom_auth.attempts (
    site_id bigint NOT NULL REFERENCES axiom_identity.sites(id),
    kind text NOT NULL CHECK (kind IN ('account','peer')),
    key text NOT NULL CHECK (octet_length(key)=64 AND key ~ '^[a-f0-9]{64}$'),
    started_at timestamptz NOT NULL,
    attempts integer NOT NULL CHECK (attempts>0),
    PRIMARY KEY(site_id,kind,key)
);
CREATE TABLE axiom_auth.sessions (
    token_hash text PRIMARY KEY CHECK (octet_length(token_hash)=64 AND token_hash ~ '^[a-f0-9]{64}$'),
    site_id bigint NOT NULL,
    user_id bigint NOT NULL,
    credential_fingerprint text NOT NULL CHECK (octet_length(credential_fingerprint)=64
        AND credential_fingerprint ~ '^[a-f0-9]{64}$'),
    created_at timestamptz NOT NULL,
    last_seen_at timestamptz NOT NULL,
    expires_at timestamptz NOT NULL,
    FOREIGN KEY(site_id,user_id) REFERENCES axiom_identity.memberships(site_id,user_id) ON DELETE CASCADE,
    CHECK(expires_at>created_at AND expires_at<=created_at+interval '8 hours'),
    CHECK(last_seen_at>=created_at AND last_seen_at<=expires_at)
);
CREATE INDEX sessions_by_user ON axiom_auth.sessions(site_id,user_id,created_at);
CREATE FUNCTION axiom_auth.site() RETURNS bigint LANGUAGE sql STABLE SECURITY DEFINER
SET search_path=pg_catalog,axiom_auth,pg_temp AS $site$
    SELECT site_id FROM axiom_auth.sites WHERE database_role=session_user::regrole::oid
$site$;

CREATE FUNCTION axiom_auth.begin_login(login_name text, peer_key text)
RETURNS TABLE(site_id bigint,user_id bigint,password_hash text,throttled boolean)
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,axiom_auth,pg_temp AS $login$
DECLARE
    scope bigint := axiom_auth.site();
    account_key text := encode(sha256(convert_to(login_name,'UTF8')),'hex');
    account_count integer;
    peer_count integer;
BEGIN
    IF scope IS NULL OR octet_length(login_name) NOT BETWEEN 1 AND 64
       OR login_name ~ '[^a-z0-9._-]' OR octet_length(peer_key)<>64 OR peer_key !~ '^[a-f0-9]{64}$'
    THEN RAISE EXCEPTION 'Invalid authentication scope' USING ERRCODE='42501'; END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended('axiom-auth:'||scope,0));
    DELETE FROM axiom_auth.attempts a WHERE a.site_id=scope AND a.started_at<=clock_timestamp()-interval '15 minutes';
    IF (SELECT count(*) FROM axiom_auth.attempts a WHERE a.site_id=scope)>=4094 THEN
        RETURN QUERY SELECT scope,NULL::bigint,NULL::text,true; RETURN;
    END IF;
    INSERT INTO axiom_auth.attempts VALUES(scope,'account',account_key,clock_timestamp(),1)
        ON CONFLICT ON CONSTRAINT attempts_pkey DO UPDATE SET attempts=least(axiom_auth.attempts.attempts+1,100000)
        RETURNING attempts INTO account_count;
    INSERT INTO axiom_auth.attempts VALUES(scope,'peer',peer_key,clock_timestamp(),1)
        ON CONFLICT ON CONSTRAINT attempts_pkey DO UPDATE SET attempts=least(axiom_auth.attempts.attempts+1,100000)
        RETURNING attempts INTO peer_count;
    IF account_count>5 OR peer_count>30 THEN
        RETURN QUERY SELECT scope,NULL::bigint,NULL::text,true; RETURN;
    END IF;
    RETURN QUERY SELECT scope,u.id,c.password_hash,false FROM axiom_identity.users u
        JOIN axiom_identity.credentials c ON c.user_id=u.id
        JOIN axiom_identity.memberships m ON m.user_id=u.id AND m.site_id=scope
        WHERE u.username=login_name AND u.disabled_at IS NULL AND m.disabled_at IS NULL;
    IF NOT FOUND THEN RETURN QUERY SELECT scope,NULL::bigint,NULL::text,false; END IF;
END;
$login$;

CREATE FUNCTION axiom_auth.create_session(actor bigint, expected_hash text, new_token text, old_token text)
RETURNS TABLE(user_id bigint,site_id bigint,role text,expires_at timestamptz)
LANGUAGE plpgsql SECURITY DEFINER SET search_path=pg_catalog,axiom_auth,pg_temp AS $create$
DECLARE
    scope bigint := axiom_auth.site();
    account_name text;
    member_role text;
    instant timestamptz;
BEGIN
    IF scope IS NULL OR octet_length(new_token)<>64 OR new_token !~ '^[a-f0-9]{64}$'
    THEN RAISE EXCEPTION 'Invalid authentication scope' USING ERRCODE='42501'; END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended('axiom-auth:'||scope,0));
    SELECT u.username,m.role INTO account_name,member_role FROM axiom_identity.users u
        JOIN axiom_identity.credentials c ON c.user_id=u.id
        JOIN axiom_identity.memberships m ON m.user_id=u.id AND m.site_id=scope
        WHERE u.id=actor AND c.password_hash=expected_hash AND u.disabled_at IS NULL AND m.disabled_at IS NULL
        FOR SHARE OF u,c,m;
    IF NOT FOUND THEN RETURN; END IF;
    instant := clock_timestamp();
    DELETE FROM axiom_auth.sessions s WHERE s.site_id=scope
        AND (s.expires_at<=instant OR s.last_seen_at<=instant-interval '30 minutes');
    DELETE FROM axiom_auth.sessions s WHERE s.site_id=scope AND s.token_hash=old_token;
    DELETE FROM axiom_auth.sessions s WHERE s.site_id=scope AND s.user_id=actor AND s.token_hash IN (
        SELECT p.token_hash FROM axiom_auth.sessions p WHERE p.site_id=scope AND p.user_id=actor
        ORDER BY p.created_at DESC,p.token_hash OFFSET 4
    );
    IF (SELECT count(*) FROM axiom_auth.sessions s WHERE s.site_id=scope)>=1024 THEN RETURN; END IF;
    INSERT INTO axiom_auth.sessions VALUES(new_token,scope,actor,
        encode(sha256(convert_to(expected_hash,'UTF8')),'hex'),instant,instant,instant+interval '8 hours');
    DELETE FROM axiom_auth.attempts a WHERE a.site_id=scope AND a.kind='account'
        AND a.key=encode(sha256(convert_to(account_name,'UTF8')),'hex');
    RETURN QUERY SELECT actor,scope,member_role,instant+interval '8 hours';
END;
$create$;

CREATE FUNCTION axiom_auth.resolve_session(requested_token text)
RETURNS TABLE(user_id bigint,site_id bigint,role text,expires_at timestamptz)
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,axiom_auth,pg_temp AS $resolve$
    UPDATE axiom_auth.sessions s SET last_seen_at=least(clock_timestamp(),s.expires_at)
    FROM axiom_identity.users u,axiom_identity.credentials c,axiom_identity.memberships m
    WHERE s.token_hash=requested_token AND s.site_id=axiom_auth.site()
        AND s.expires_at>clock_timestamp() AND s.last_seen_at>clock_timestamp()-interval '30 minutes'
        AND u.id=s.user_id AND c.user_id=u.id AND m.user_id=u.id AND m.site_id=s.site_id
        AND u.disabled_at IS NULL AND m.disabled_at IS NULL
        AND s.credential_fingerprint=encode(sha256(convert_to(c.password_hash,'UTF8')),'hex')
    RETURNING u.id,s.site_id,m.role,s.expires_at
$resolve$;
CREATE FUNCTION axiom_auth.revoke_session(requested_token text) RETURNS void
LANGUAGE sql VOLATILE SECURITY DEFINER SET search_path=pg_catalog,axiom_auth,pg_temp AS $revoke$
    DELETE FROM axiom_auth.sessions WHERE token_hash=requested_token AND site_id=axiom_auth.site()
$revoke$;
REVOKE ALL ON ALL FUNCTIONS IN SCHEMA axiom_auth FROM PUBLIC;
GRANT USAGE ON SCHEMA axiom_auth TO axiom_authenticator;
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA axiom_auth TO axiom_authenticator;
