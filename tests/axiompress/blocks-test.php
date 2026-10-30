<?php

declare(strict_types=1);

use AxiomPress\Core\Blocks\Document;

require dirname(__DIR__, 2) . '/src/axiompress/bootstrap.php';

$checks = 0;
$check = static function (bool $value) use (&$checks): void {
    if (!$value) {
        throw new RuntimeException('Block document contract failed.');
    }
    $checks++;
};
$blocks = array(array('type' => 'paragraph', 'text' => "Hello κόσμε <script>not code</script> & \"quotes\"\nnext line"),
    array('type' => 'heading', 'level' => 2, 'text' => 'A heading'),
    array('type' => 'image', 'media' => str_repeat('a', 32), 'alt' => '<Image> & "detail"'));
$source = Document::serialize($blocks);
if (($argv[1] ?? '') === '--fixtures') {
    echo json_encode(array('source' => $source, 'blocks' => $blocks), JSON_THROW_ON_ERROR);
    exit;
}
$check(Document::parse($source) === $blocks);
$check(Document::serialize(Document::parse($source)) === $source);
$check(Document::parse('') === array());
$check(Document::parse('<script>plain source</script>')[0]['text'] === '<script>plain source</script>');
$check(!str_contains($source, '<script>'));
$rich = array(array('type' => 'rich-paragraph', 'html' => 'Hello <strong>bold <em>κόσμε</em></strong><br>&lt;text&gt;'),
    array('type' => 'rich-heading', 'level' => 3, 'html' => '<code>Heading</code>'));
$check(Document::parse(Document::serialize($rich)) === $rich);
foreach (
    array('<script>x</script>', '<strong onclick="x">x</strong>', '<a href="javascript:x">x</a>',
    '<em><strong>x</em></strong>', '<strong>x', '</em>', '<img src=x>', '<!-- hidden -->', '&lt;script>',
    str_repeat('<em>', 9) . 'x' . str_repeat('</em>', 9), "\xff", "\0") as $unsafe
) {
    try {
        Document::inline($unsafe);
        throw new LogicException('Unsafe rich text accepted.');
    } catch (RuntimeException $error) {
        $check(true);
    }
}
$check(Document::inline('') === '');
foreach (
    array('<!-- wp:html --><script>alert(1)</script><!-- /wp:html -->',
    '<!-- wp:paragraph -->' . "\n<p><img onerror=x></p>\n" . '<!-- /wp:paragraph -->',
    '<!-- wp:axiom/image {"media":"bad","alt":""} /-->', $source . "\n\n", "\xff") as $invalid
) {
    try {
        Document::parse($invalid);
        throw new LogicException('Unsafe block source accepted.');
    } catch (RuntimeException $error) {
        $check(true);
    }
}
foreach (
    array(array(array('type' => 'callback', 'function' => 'eval')),
    array(array('type' => 'heading', 'level' => 1, 'text' => 'Invalid level')),
    array(array('type' => 'paragraph', 'text' => "\xff")),
    array_fill(0, 65, array('type' => 'paragraph', 'text' => 'Too many')),
    array(array('type' => 'paragraph', 'text' => str_repeat('x', 131073)))) as $invalid
) {
    try {
        Document::serialize($invalid);
        throw new LogicException('Unbounded/unsupported document accepted.');
    } catch (RuntimeException $error) {
        $check(true);
    }
}
for ($level = 2; $level <= 4; $level++) {
    $value = array(array('type' => 'heading', 'level' => $level, 'text' => 'a&b < > " ' . "'\n😀"));
    $check(Document::parse(Document::serialize($value)) === $value);
}
echo "PASS: $checks declarative block checks\n";
