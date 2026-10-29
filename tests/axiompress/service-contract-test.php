<?php

declare(strict_types=1);

use AxiomPress\Service\Invocation;

require dirname(__DIR__, 2) . '/tools/axiompress/broker/bootstrap.php';
require dirname(__DIR__, 2) . '/tools/axiompress/service/Invocation.php';

$valid = (object) array('api' => 'axiompress.core-broker/0.1', 'installation' => 'reading-summary',
    'site' => 'site:1', 'content' => (object) array('content_id' => 'post:1', 'revision' => str_repeat('a', 64),
        'title' => 'Title', 'text' => 'κόσμε café 你好'));
$checks = 0;
foreach (array('', 'κόσμε café 你好', str_repeat('x', 131072)) as $text) {
    $input = unserialize(serialize($valid), array('allowed_classes' => array('stdClass')));
    $input->content->text = $text;
    if ((new Invocation($input))->content->text !== $text) {
        throw new RuntimeException('Valid snapshot changed.');
    }
    $checks++;
}
$cases = array(
    array('actor', 'admin'), array('runtime', 'runc'), array('api', 'unknown'), array('site', 'site:01'),
    array('site', 'site:0'), array('site', 1), array('installation', '../escape'), array('installation', null),
    array('content', array()), array('installation', str_repeat('x', 129)),
);
foreach (
    array('content_id' => 'post:01', 'revision' => 'v1', 'text' => str_repeat('x', 131073),
    'title' => str_repeat('x', 4097), 'actor' => 'admin') as $key => $value
) {
    $cases[] = array('content.' . $key, $value);
}
foreach ($cases as [$key, $value]) {
    $input = unserialize(serialize($valid), array('allowed_classes' => array('stdClass')));
    if (str_starts_with($key, 'content.')) {
        $input->content->{substr($key, 8)} = $value;
    } else {
        $input->{$key} = $value;
    }
    try {
        new Invocation($input);
    } catch (InvalidArgumentException | AxiomPress\Protocol\ProtocolError $error) {
        $checks++;
        continue;
    }
    throw new RuntimeException('Invalid snapshot admitted: ' . $key);
}
echo "PASS: $checks service contract checks\n";
