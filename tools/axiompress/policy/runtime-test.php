<?php

declare(strict_types=1);

use AxiomPress\Policy\Package;
use AxiomPress\Policy\ImageBinding;
use AxiomPress\Policy\PolicyStore;
use AxiomPress\Supervisor\Supervisor;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/../supervisor/Supervisor.php';
$image = (string) getenv('AXIOM_APPROVED_IMAGE');
$sdk = (string) getenv('AXIOM_SDK_IMAGE');
$package = Package::capture('/app/packages/reading-summary');
$binding = ImageBinding::inspect($package, Supervisor::inspectImage($image), $sdk);
$store = new PolicyStore('/tmp/runtime-policy.sqlite');
$store->register($binding);
$store->create('runtime-policy', 'site:1', $image);
$store->change('runtime-policy', 1, true, array('content.read'), $image);
$reader = new PolicyStore('/tmp/runtime-policy.sqlite', true);
$writer = new PolicyStore('/tmp/runtime-policy.sqlite');
mkdir('/tmp/admission', 0700);
Supervisor::reconcile();
$canRead = static fn(string $actor, string $site, string $id): bool =>
    $actor === 'editor' && $site === 'site:1' && $id === 'post:1';
$revokingRead = static function (string $site, string $id) use ($writer, $image): stdClass {
    $writer->change('runtime-policy', 2, false, array(), $image);
    return (object) array('content_id' => $id, 'revision' => 'v1', 'title' => 'Fixture', 'text' => 'Private fixture.');
};
$supervisor = Supervisor::fromPolicy($reader, 'runtime-policy', 'site:1', $canRead, $revokingRead, '/tmp/admission');
try {
    $supervisor->run('runtime-policy', 'site:1', 'editor', 'post:1');
    throw new LogicException('Revoked grant produced a component.');
} catch (RuntimeException $error) {
    if ($supervisor->lastBrokerDenial() !== 'forbidden') {
        throw $error;
    }
}
if (Supervisor::remainingWorkers() !== array()) {
    throw new RuntimeException('Revocation left a worker container.');
}
try {
    Supervisor::fromPolicy($reader, 'runtime-policy', 'site:1', $canRead, $revokingRead);
    throw new LogicException('Disabled installation was admitted.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Installation is not authorized.') {
        throw $error;
    }
}
$generation = $writer->change('runtime-policy', 3, true, array('content.read'), $image);
$fixtureText = 'One two three four five.';
$read = static function (string $site, string $id) use (&$fixtureText): stdClass {
    return (object) array('content_id' => $id, 'revision' => 'v2', 'title' => 'Fixture', 'text' => $fixtureText);
};
$fresh = Supervisor::fromPolicy($reader, 'runtime-policy', 'site:1', $canRead, $read, '/tmp/admission');
$result = $fresh->run('runtime-policy', 'site:1', 'editor', 'post:1');
if ($generation !== 4 || $result->component->word_count !== 5 || Supervisor::remainingWorkers() !== array()) {
    throw new RuntimeException('New grant generation or cleanup failed.');
}
$textCases = array();
foreach (array('' => array(0, 0), 'κόσμε café 你好' => array(3, 1)) as $fixtureText => [$words, $seconds]) {
    $component = $fresh->run('runtime-policy', 'site:1', 'editor', 'post:1')->component;
    if ($component->word_count !== $words || $component->reading_seconds !== $seconds) {
        throw new RuntimeException('Empty/Unicode useful package behavior mismatch.');
    }
    $textCases[] = $component;
}
echo json_encode(array('outcome' => 'passed', 'disabled_mid_read' => true,
    'disabled_admission_denied' => true, 'reenabled_generation' => $generation,
    'component' => $result->component, 'text_cases' => $textCases, 'image' => $image), JSON_THROW_ON_ERROR) . "\n";
