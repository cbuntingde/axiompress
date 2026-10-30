-- Administrator-only prerequisite for Phase 4 migrations on the dedicated cluster.
BEGIN;
DO $guard$
BEGIN
    IF NOT current_setting('is_superuser')::boolean OR NOT EXISTS (
        SELECT FROM pg_roles WHERE rolname='axiom_owner' AND NOT rolcanlogin AND NOT rolsuper
    ) THEN RAISE EXCEPTION 'AxiomPress administrator bootstrap required'; END IF;
END;
$guard$;
CREATE ROLE axiom_job_runner NOLOGIN NOINHERIT NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS;
DO $connect$
BEGIN EXECUTE format('GRANT CONNECT ON DATABASE %I TO axiom_job_runner',current_database()); END;
$connect$;
COMMIT;
