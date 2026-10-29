<?php

declare(strict_types=1);

use AxiomPress\Core\Configuration;
use AxiomPress\Core\ComponentRenderer;
use AxiomPress\Core\ContentLimitExceeded;
use AxiomPress\Core\Kernel;
use AxiomPress\Core\PublicContentRepository;

require dirname(__DIR__, 2) . '/src/axiompress/bootstrap.php';

$checks = 0;
$check = static function (bool $condition) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('Native core assertion failed.');
    }
    $checks++;
};
foreach (array('é', '\\', '"', chr(1)) as $character) {
    $large = array('text' => str_repeat($character, 65536), 'title' => 'Escaped " title \\');
    $encoded = json_encode($large, JSON_THROW_ON_ERROR);
    $check(AxiomPress\Core\JsonInput::object($encoded, array('text', 'title')) === $large);
}
foreach (
    array('{"text":"a","text":"b","title":""}', '{"text":1,"title":""}',
    '{"text":"","title":"","extra":""}', '{"text":"\\u0000","title":""}', '[]', '{') as $invalid
) {
    $check(AxiomPress\Core\JsonInput::object($invalid, array('text', 'title')) === null);
}
$db = PDO::connect('sqlite::memory:', null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->createFunction('OCTET_LENGTH', static fn(string $value): int => strlen($value), 1);
$db->exec('CREATE TABLE axiompress_public_test (ID INTEGER PRIMARY KEY, post_title TEXT,
    post_content TEXT, post_status TEXT, post_password TEXT, post_type TEXT,
    post_modified_gmt TEXT, post_date_gmt TEXT)');
$insert = $db->prepare('INSERT INTO axiompress_public_test VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$cases = array(
    array(1, '<b>Public &amp; safe</b>', 'Hello κόσμε [shortcode] <script>alert(1)</script>', 'publish', '', 'post'),
    array(2, 'Private', 'Hidden', 'private', '', 'post'),
    array(3, 'Draft', 'Hidden', 'draft', '', 'post'),
    array(4, 'Password', 'Hidden', 'publish', 'secret-fixture', 'post'),
    array(5, 'Revision', 'Hidden', 'publish', '', 'revision'),
    array(6, 'Attachment', 'Hidden', 'publish', '', 'attachment'),
    array(7, 'Trashed', 'Hidden', 'trash', '', 'post'),
    array(8, 'Future', 'Hidden', 'publish', '', 'post'),
    array(9, 'Pending', 'Hidden', 'pending', '', 'post'),
    array(10, 'Page', '', 'publish', '', 'page'),
    array(11, 'Large', str_repeat('x', 131073), 'publish', '', 'post'),
    array(12, str_repeat('x', 4097), 'Small', 'publish', '', 'post'),
);
foreach ($cases as $row) {
    $insert->execute(array_merge($row, array('2026-01-01 00:00:00',
        $row[0] === 8 ? '9999-12-31 23:59:59' : '2026-01-01 00:00:00')));
}
$repository = new PublicContentRepository($db, 'axiompress_public_test', 1);
$kernel = new Kernel(static fn(): PublicContentRepository => $repository);
$response = $kernel->handle('GET', '/v1/sites/1/content/1');
$check($response->status === 200);
$check($response->data['content']['title'] === 'Public & safe');
$check($response->data['content']['text'] === 'Hello κόσμε [shortcode] alert(1)');
$check(!str_contains($response->body(), '<script'));
$check(array_keys($response->data['content']) === array('site_id', 'content_id', 'revision', 'title', 'text'));
$revision = $response->data['content']['revision'];
for ($id = 2; $id <= 9; $id++) {
    $check($kernel->handle('GET', '/v1/sites/1/content/' . $id)->status === 404);
}
$check($kernel->handle('GET', '/v1/sites/1/content/10')->data['content']['text'] === '');
$check($kernel->handle('GET', '/v1/sites/2/content/1')->status === 404);
$check($kernel->handle('GET', '/v1/sites/1/content/99999')->data === array('error' => 'not_found'));
$check($kernel->handle('POST', '/v1/sites/1/content/1')->status === 405);
$check($kernel->handle('HEAD', '/v1/sites/1/content/1')->status === 200);
foreach (array('01', '-1', '0', '1.0', '1e0', '1%20OR%201=1', '../1', '1?actor=admin', '1/extra') as $id) {
    $check($kernel->handle('GET', '/v1/sites/1/content/' . $id)->status === 404);
}
$check($kernel->handle('GET', '/' . str_repeat('x', 256))->status === 414);
foreach (array(11, 12) as $id) {
    try {
        $repository->read(1, $id);
        throw new LogicException('Oversized content was returned.');
    } catch (ContentLimitExceeded $error) {
        $check(true);
    }
}
$db->exec("UPDATE axiompress_public_test SET post_status='private' WHERE ID=1");
$check($repository->read(1, 1) === null);
$db->exec("UPDATE axiompress_public_test SET post_status='publish', post_content='Changed' WHERE ID=1");
$check($repository->read(1, 1)['revision'] !== $revision);
foreach (array('axiompress_public_x` WHERE 1=1 --', 'wp_posts', '../table') as $view) {
    try {
        new PublicContentRepository($db, $view, 1);
        throw new LogicException('Unsafe view identifier accepted.');
    } catch (RuntimeException $error) {
        $check(true);
    }
}
try {
    new Configuration('mysql;dbname=other', 3306, 'db', 'user', '/secret', 'axiompress_public_test', 1);
    throw new LogicException('DSN injection accepted.');
} catch (RuntimeException $error) {
    $check(true);
}
$secretFile = '/tmp/axiompress-native-secret-' . bin2hex(random_bytes(12));
$configuration = new Configuration('mysql', 3306, 'database', 'reader', $secretFile, 'axiompress_public_test', 1);
try {
    foreach (array(15, 257) as $bytes) {
        file_put_contents($secretFile, str_repeat('x', $bytes));
        try {
            $configuration->password();
            throw new LogicException('Invalid secret size accepted.');
        } catch (RuntimeException $error) {
            $check($error->getMessage() === 'Invalid native core secret.');
        }
    }
    file_put_contents($secretFile, str_repeat('x', 64));
    $check($configuration->password() === str_repeat('x', 64));
    symlink($secretFile, $secretFile . '.link');
    try {
        (new Configuration(
            'mysql',
            3306,
            'database',
            'reader',
            $secretFile . '.link',
            'axiompress_public_test',
            1
        ))->password();
        throw new LogicException('Linked secret accepted.');
    } catch (RuntimeException $error) {
        $check($error->getMessage() === 'Native core secret unavailable.');
    }
} finally {
    if (is_link($secretFile . '.link')) {
        unlink($secretFile . '.link');
    }
    unlink($secretFile);
}
$check(!defined('ABSPATH') && !function_exists('add_action') && !class_exists('wpdb', false));
$component = (object) array('type' => 'reading_summary', 'word_count' => 5, 'reading_seconds' => 2);
$check(ComponentRenderer::readingSummary($component)
    === '<span class="axiompress-reading-summary">5 words · 2 seconds reading time</span>');
foreach (
    array(
    (object) array('type' => 'reading_summary', 'word_count' => '<script>alert(1)</script>', 'reading_seconds' => 2),
    (object) array('type' => 'reading_summary', 'word_count' => 5, 'reading_seconds' => 2, 'html' => '<script>'),
    (object) array('type' => 'reading_summary', 'word_count' => -1, 'reading_seconds' => 0),
    (object) array('type' => 'reading_summary', 'word_count' => 1.5, 'reading_seconds' => 1),
    (object) array('type' => 'reading_summary', 'word_count' => 131073, 'reading_seconds' => 1),
    (object) array('type' => 'reading_summary', 'word_count' => 5, 'reading_seconds' => 39323),
    (object) array('type' => 'reading_summary', 'word_count' => 5),
    ) as $invalid
) {
    try {
        ComponentRenderer::readingSummary($invalid);
        throw new LogicException('Unsafe component accepted.');
    } catch (InvalidArgumentException $error) {
        $check(true);
    }
}
foreach (get_included_files() as $file) {
    $check(str_starts_with($file, dirname(__DIR__, 2) . '/src/axiompress/') || $file === __FILE__);
}
echo "PASS: $checks native core checks\n";
