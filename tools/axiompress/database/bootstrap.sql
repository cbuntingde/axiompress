-- Operator-only: run once as the administrator of a new, empty dedicated database.
-- No passwords are installed here. Provision deployment/login secrets separately.
BEGIN;
DO $bootstrap$
BEGIN
    IF NOT current_setting('is_superuser')::boolean THEN
        RAISE EXCEPTION 'Database administrator required';
    END IF;
    IF EXISTS (SELECT FROM pg_namespace WHERE nspname !~ '^pg_'
               AND nspname NOT IN ('public', 'information_schema'))
       OR EXISTS (SELECT FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
                  WHERE n.nspname = 'public')
       OR EXISTS (SELECT FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
                  WHERE n.nspname = 'public') THEN
        RAISE EXCEPTION 'Refusing to bootstrap a nonempty database';
    END IF;
END;
$bootstrap$;
CREATE ROLE axiom_owner NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE axiom_deployer LOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
CREATE ROLE axiom_reader NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
GRANT axiom_owner TO axiom_deployer WITH INHERIT FALSE, SET TRUE;
REVOKE ALL ON SCHEMA public FROM PUBLIC;
DO $database$
BEGIN
    EXECUTE format('REVOKE ALL ON DATABASE %I FROM PUBLIC', current_database());
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO axiom_deployer, axiom_reader', current_database());
    EXECUTE format('GRANT CREATE ON DATABASE %I TO axiom_owner', current_database());
END;
$database$;
ALTER DEFAULT PRIVILEGES FOR ROLE axiom_owner REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
COMMIT;
