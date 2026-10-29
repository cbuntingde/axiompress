CREATE SCHEMA axiom_identity AUTHORIZATION axiom_owner;
CREATE SCHEMA axiom_access AUTHORIZATION axiom_owner;
CREATE SCHEMA axiom_content AUTHORIZATION axiom_owner;
REVOKE ALL ON SCHEMA axiom_identity, axiom_access, axiom_content FROM PUBLIC;

CREATE TABLE axiom_identity.sites (
    id bigint GENERATED ALWAYS AS IDENTITY (MAXVALUE 9999999999) PRIMARY KEY CHECK (id > 0),
    slug text NOT NULL UNIQUE CHECK (octet_length(slug) BETWEEN 1 AND 63
        AND slug !~ '[^a-z0-9-]' AND slug ~ '^[a-z0-9]' AND slug ~ '[a-z0-9]$'),
    name text NOT NULL CHECK (octet_length(name) BETWEEN 1 AND 256),
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE axiom_identity.users (
    id bigint GENERATED ALWAYS AS IDENTITY (MAXVALUE 999999999999999999) PRIMARY KEY CHECK (id > 0),
    username text NOT NULL UNIQUE CHECK (octet_length(username) BETWEEN 1 AND 64
        AND username !~ '[^a-z0-9._-]'),
    display_name text NOT NULL CHECK (octet_length(display_name) BETWEEN 1 AND 256),
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    disabled_at timestamptz
);
CREATE TABLE axiom_identity.credentials (
    user_id bigint PRIMARY KEY REFERENCES axiom_identity.users(id),
    password_hash text NOT NULL CHECK (octet_length(password_hash) BETWEEN 32 AND 255
        AND left(password_hash, 10) = '$argon2id$'),
    changed_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE axiom_identity.memberships (
    site_id bigint NOT NULL REFERENCES axiom_identity.sites(id),
    user_id bigint NOT NULL REFERENCES axiom_identity.users(id),
    role text NOT NULL CHECK (role IN ('owner', 'editor', 'author')),
    PRIMARY KEY (site_id, user_id)
);

-- Role OIDs bind the authenticated database login, not a caller-controlled GUC or SET ROLE.
-- Dropping/recreating a login with the same name does not inherit the old login's binding.
CREATE TABLE axiom_access.reader_sites (
    database_role oid NOT NULL,
    site_id bigint NOT NULL REFERENCES axiom_identity.sites(id),
    PRIMARY KEY (database_role, site_id)
);
CREATE FUNCTION axiom_access.reader_can_access(requested_site bigint) RETURNS boolean
LANGUAGE sql STABLE SECURITY DEFINER
SET search_path = pg_catalog, axiom_access, pg_temp
AS $scope$
    SELECT EXISTS (SELECT FROM axiom_access.reader_sites
                   WHERE database_role = session_user::regrole::oid AND site_id = requested_site)
$scope$;
REVOKE ALL ON FUNCTION axiom_access.reader_can_access(bigint) FROM PUBLIC;
GRANT USAGE ON SCHEMA axiom_access, axiom_content TO axiom_reader;
GRANT EXECUTE ON FUNCTION axiom_access.reader_can_access(bigint) TO axiom_reader;

CREATE TABLE axiom_content.articles (
    site_id bigint NOT NULL REFERENCES axiom_identity.sites(id),
    id bigint GENERATED ALWAYS AS IDENTITY (MAXVALUE 999999999999999999) CHECK (id > 0),
    author_id bigint NOT NULL,
    status text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'published', 'archived')),
    lock_version bigint NOT NULL DEFAULT 1 CHECK (lock_version > 0),
    current_revision_id text NOT NULL CHECK (current_revision_id ~ '^[a-f0-9]{64}$'
        AND octet_length(current_revision_id) = 64),
    published_revision_id text,
    published_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (site_id, id),
    FOREIGN KEY (site_id, author_id) REFERENCES axiom_identity.memberships(site_id, user_id),
    CHECK ((status = 'published' AND published_revision_id IS NOT NULL AND published_at IS NOT NULL)
        OR (status <> 'published' AND published_revision_id IS NULL AND published_at IS NULL))
);
CREATE TABLE axiom_content.revisions (
    site_id bigint NOT NULL,
    article_id bigint NOT NULL,
    revision_id text NOT NULL CHECK (revision_id ~ '^[a-f0-9]{64}$' AND octet_length(revision_id) = 64),
    version bigint NOT NULL CHECK (version > 0),
    title text NOT NULL CHECK (octet_length(title) <= 4096),
    body text NOT NULL CHECK (octet_length(body) <= 131072),
    created_by bigint NOT NULL,
    created_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (site_id, article_id, revision_id),
    UNIQUE (site_id, article_id, version),
    FOREIGN KEY (site_id, article_id) REFERENCES axiom_content.articles(site_id, id)
        DEFERRABLE INITIALLY DEFERRED,
    FOREIGN KEY (site_id, created_by) REFERENCES axiom_identity.memberships(site_id, user_id)
);
ALTER TABLE axiom_content.articles ADD CONSTRAINT current_revision_scope
    FOREIGN KEY (site_id, id, current_revision_id)
    REFERENCES axiom_content.revisions(site_id, article_id, revision_id) DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE axiom_content.articles ADD CONSTRAINT published_revision_scope
    FOREIGN KEY (site_id, id, published_revision_id)
    REFERENCES axiom_content.revisions(site_id, article_id, revision_id) DEFERRABLE INITIALLY DEFERRED;
CREATE INDEX public_articles ON axiom_content.articles(site_id, published_at, id) WHERE status = 'published';

ALTER TABLE axiom_content.articles ENABLE ROW LEVEL SECURITY;
ALTER TABLE axiom_content.articles FORCE ROW LEVEL SECURITY;
ALTER TABLE axiom_content.revisions ENABLE ROW LEVEL SECURITY;
ALTER TABLE axiom_content.revisions FORCE ROW LEVEL SECURITY;
CREATE POLICY deployment_articles ON axiom_content.articles TO axiom_owner USING (true) WITH CHECK (true);
CREATE POLICY deployment_revisions ON axiom_content.revisions TO axiom_owner USING (true) WITH CHECK (true);
CREATE POLICY public_articles ON axiom_content.articles FOR SELECT TO axiom_reader
    USING (axiom_access.reader_can_access(site_id) AND status = 'published'
           AND published_at <= statement_timestamp());
CREATE POLICY public_revisions ON axiom_content.revisions FOR SELECT TO axiom_reader
    USING (axiom_access.reader_can_access(site_id) AND EXISTS (
        SELECT FROM axiom_content.articles a WHERE a.site_id = revisions.site_id
        AND a.id = revisions.article_id AND a.published_revision_id = revisions.revision_id
    ));
-- No credential, membership, draft-pointer, identity-sequence or write authority is granted.
GRANT SELECT (site_id, id, published_revision_id, published_at) ON axiom_content.articles TO axiom_reader;
GRANT SELECT (site_id, article_id, revision_id, title, body, created_at)
    ON axiom_content.revisions TO axiom_reader;
