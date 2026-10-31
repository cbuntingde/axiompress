<?php

declare(strict_types=1);

use AxiomPress\Core\Auth\Store;
use AxiomPress\Core\Blocks\Document;
use AxiomPress\Core\Platform\Kernel;
use AxiomPress\Core\Platform\PublicRepository;
use AxiomPress\Database\Connection;

require '/core/bootstrap.php';
require '/app/Connection.php';
$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $checks++;
};
$admin = Connection::fromEnvironment();
$admin->exec('SET ROLE axiom_owner');
$connect = static fn(string $user, string $file): PDO => PDO::connect(
    'pgsql:host=postgres;dbname=axiom_auth_test;sslmode=verify-full;sslrootcert=/run/secrets/ca.crt',
    $user,
    file_get_contents('/run/secrets/' . $file),
    array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
);
$auth = $connect('auth_a', 'auth-a');
$store = new Store($auth, 1);
$hash = $admin->query('SELECT password_hash FROM axiom_identity.credentials WHERE user_id=1')->fetchColumn();
$token = bin2hex(random_bytes(32));
$store->create(1, $hash, $token, '');
$key = static fn(): string => bin2hex(random_bytes(32));
$command = static fn(string $op, array $args = array()): array => $store->platform($token, $op, $args, $key());
$listed = $command('articles.list', array('cursor' => '', 'q' => '', 'status' => ''));
$check(isset($listed['items']), 'Native bounded article query');
$check(
    ($command('articles.list', array('cursor' => '0', 'q' => '', 'status' => ''))['error'] ?? '')
        === 'invalid_request',
    'Canonical list cursor'
);
$check(
    ($command('articles.list', array('cursor' => '', 'q' => '', 'status' => 'archived'))['error'] ?? '')
        === 'invalid_request',
    'List status is a closed set'
);
$check(
    ($command('articles.list', array('cursor' => '', 'q' => str_repeat('x', 65), 'status' => ''))['error'] ?? '')
        === 'invalid_request',
    'List search stays bounded'
);
$settings = $command('settings.read');
$fields = array('expected_version' => $settings['version'],
    'title' => 'Native site', 'tagline' => '<script>text</script>',
    'theme' => 'ink', 'page_size' => '10');
$check(($command('settings.write', $fields)['error'] ?? '') === 'forbidden', 'Editor cannot change owner settings');
$admin->exec("UPDATE axiom_identity.memberships SET role='owner' WHERE site_id=1 AND user_id=1");
$written = $command('settings.write', $fields);
$check(
    $written['version'] === '2' && $command('settings.read')['title'] === 'Native site',
    'Owner updates typed settings'
);
$check(($command('settings.write', $fields)['error'] ?? '') === 'conflict', 'Setting compare-and-swap');
$reader = new PublicRepository($connect('reader_a', 'reader-a'), 1);
$check($reader->settings(1)['theme'] === 'ink' && $reader->settings(2) === null, 'Public declarative settings scoped');
$term = $command('terms.write', array('id' => '', 'kind' => 'category', 'name' => 'News', 'slug' => 'news',
    'expected_version' => '0'));
$check(isset($term['id']) && $term['version'] === '1', 'Typed taxonomy creation');
$check(count($command('terms.list')['items']) === 1, 'Bounded taxonomy query');
$created = $store->content($token, 'create', '0', array('title' => 'Tagged article', 'text' => 'Plain source'), $key());
$id = $created['id'];
$expected = static fn(array $r): array => array(
    'expected_revision' => $r['revision'], 'expected_version' => $r['version'],
);
$attached = $command('relationships.write', $expected($created) + array('id' => $id,
    'terms' => json_encode(array($term['id']), JSON_THROW_ON_ERROR)));
$check(isset($attached['revision']) && $attached['revision'] !== $created['revision'], 'Taxonomy is revision-scoped');
$published = $store->content($token, 'publish', $id, $expected($attached), $key());
$check(
    ($published['status'] ?? '') === 'published' && $reader->terms(1, (int) $id)[0]['name'] === 'News',
    'Explicit publication exposes taxonomy snapshot'
);
$empty = $command('relationships.write', $expected($published) + array('id' => $id, 'terms' => '[]'));
$check($reader->terms(1, (int) $id)[0]['name'] === 'News', 'Private taxonomy change never replaces public snapshot');
$restored = $command('articles.restore', $expected($empty) + array('id' => $id, 'revision' => $attached['revision']));
$check(isset($restored['revision']) && $restored['revision'] !== $attached['revision'], 'Restore appends new revision');
$read = $store->content($token, 'read', $id, array(), '');
$check($read['terms'] === array($term['id']), 'Restored snapshot retains taxonomy');
$check(count($command('revisions.list', array('id' => $id))['items']) === 4, 'Native revision history query');
$image = $store->media($token, 'create', '', array('name' => 'SQL fixture', 'mime' => 'image/png',
    'data' => base64_encode("\x89PNG\r\n\x1a\nSQL-only fixture")), $key());
$document = Document::serialize(array(array('type' => 'image', 'media' => $image['id'], 'alt' => '<image>')));
$illustrated = $store->content($token, 'create', '0', array('title' => 'Images', 'text' => $document), $key());
$check(isset($illustrated['id']), 'Native block media reference accepted');
$check(
    ($store->content($token, 'publish', $illustrated['id'], $expected($illustrated), $key())['error'] ?? '')
        === 'conflict',
    'Publication refuses unprepared or private image references'
);
$foreign = Document::serialize(array(array('type' => 'image', 'media' => str_repeat('f', 32), 'alt' => 'Missing')));
$check(
    ($store->content($token, 'create', '0', array('title' => 'Unknown', 'text' => $foreign), $key())['error'] ?? '')
        === 'not_found',
    'Unknown media reference does not create an article'
);
$query = $admin->prepare("UPDATE axiom_media.assets SET status='failed',source=NULL WHERE id=?");
$query->execute(array($image['id']));
$list = static fn(string $status, string $q = ''): array =>
    $command('articles.list', array('cursor' => '', 'q' => $q, 'status' => $status));
$all = $list('')['items'];
$check(count($all) > 0, 'Unfiltered list returns drafts and published');
$check(
    array_all($all, static fn(array $row): bool => !array_diff_key(array_flip(array(
        'id', 'status', 'title', 'updated_at', 'scheduled_at', 'schedule_status', 'terms',
    )), $row) && is_array($row['terms'])),
    'List rows carry status, schedule and taxonomy shape'
);
$publishedOnly = $list('published')['items'];
$check(
    count($publishedOnly) > 0
        && array_all($publishedOnly, static fn(array $row): bool => $row['status'] === 'published'),
    'Status filter keeps published'
);
$tagged = array_values(array_filter(
    $publishedOnly,
    static fn(array $row): bool => $row['title'] === 'Tagged article'
));
$check(
    count($tagged) === 1
        && $tagged[0]['terms'] === array(array('kind' => 'category', 'name' => 'News')),
    'List rows carry the revision taxonomy snapshot'
);
$draftOnly = $list('draft')['items'];
$check(
    count($draftOnly) > 0
        && array_all($draftOnly, static fn(array $row): bool => $row['status'] === 'draft'),
    'Status filter keeps drafts'
);
$illustrated = array_values(array_filter(
    $draftOnly,
    static fn(array $row): bool => $row['title'] === 'Images'
));
$check(
    count($illustrated) === 1 && $illustrated[0]['terms'] === array(),
    'Draft rows without taxonomy report no terms'
);
$check(
    array_all($list('scheduled')['items'], static fn(array $row): bool => $row['status'] === 'draft'
        && $row['schedule_status'] === 'pending'),
    'Schedule filter keeps drafts with pending schedules'
);
$searched = $list('', 'tagged')['items'];
$check(
    count($searched) > 0 && array_all(
        $searched,
        static fn(array $row): bool => stripos($row['title'], 'tagged') !== false
    ),
    'Title search matches case-insensitively'
);
$check($list('', 'no such article title')['items'] === array(), 'Title search reports no match');
$overview = $command('overview.read');
$check(
    is_int($overview['draft']) && $overview['draft'] >= 1 && is_int($overview['published'])
        && $overview['published'] >= 1 && is_int($overview['scheduled']) && $overview['scheduled'] >= 0
        && is_int($overview['media']) && $overview['media'] >= 1 && is_int($overview['terms'])
        && $overview['terms'] >= 1,
    'Workspace overview counts visible content'
);
$archived = $command('terms.archive', array('id' => $term['id'], 'expected_version' => '1'));
$check($archived['version'] === '2' && $command('terms.list')['items'][0]['archived'], 'Archive preserves history');
$check(
    ($command('relationships.write', $expected($restored) + array('id' => $id,
        'terms' => json_encode(array($term['id']))))['error'] ?? '') === 'invalid_request',
    'Archived term cannot be newly assigned'
);
$admin->exec("UPDATE axiom_identity.memberships SET role='author' WHERE site_id=1 AND user_id=1");
$check(($command('terms.write', array('id' => '', 'kind' => 'tag', 'name' => 'X', 'slug' => 'x',
    'expected_version' => '0'))['error'] ?? '') === 'forbidden', 'Author cannot create global taxonomy');
$mine = $command('articles.list', array('cursor' => '', 'q' => '', 'status' => ''))['items'];
$check(count($mine) > 0, 'Author can browse own work');
$check(
    ($command('revisions.list', array('id' => '999999999999999999'))['error'] ?? '') === 'not_found',
    'Private revision probes disclose no object'
);
$kernel = new Kernel(static fn(): Store => $store, 'https://localhost');
$response = $kernel->handle(
    'POST',
    '/v1/platform/articles.list',
    '{"cursor":"","actor":"owner"}',
    '__Host-axiom_session=' . $token,
    'https://localhost',
    'application/json',
    hash_hmac('sha256', 'axiompress.csrf.v1', $token),
    $key(),
    true
);
$check($response->status === 400, 'Platform HTTP rejects authority assertions');
foreach (array('axiom_audit.events', 'axiom_platform.settings', 'axiom_platform.revision_terms') as $table) {
    try {
        $auth->query('SELECT * FROM ' . $table);
        throw new LogicException('Platform/audit table exposed.');
    } catch (PDOException $error) {
        $check($error->getCode() === '42501', 'Function-only platform identity');
    }
}
$check(
    (int) $admin->query('SELECT count(*) FROM axiom_audit.events')->fetchColumn() > 0,
    'Mutations append audit metadata'
);
$check(!$admin->query("SELECT EXISTS(SELECT FROM axiom_audit.events WHERE document::text LIKE '%<script>%')")
    ->fetchColumn(), 'Audit excludes stored content and settings values');
$admin->exec("UPDATE axiom_identity.memberships SET role='editor' WHERE site_id=1 AND user_id=1;
    DELETE FROM axiom_auth.attempts; DELETE FROM axiom_auth.sessions");
fwrite(STDOUT, json_encode(array('outcome' => 'passed', 'checks' => $checks), JSON_THROW_ON_ERROR) . "\n");
