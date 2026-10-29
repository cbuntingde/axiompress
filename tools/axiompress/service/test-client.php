<?php

declare(strict_types=1);

use AxiomPress\Core\ExtensionClient;

require '/core/bootstrap.php';

echo (new ExtensionClient($argv[2] ?? '/channel/broker.sock', 'service-reading'))->render(array(
    'site_id' => 1, 'content_id' => 1, 'revision' => str_repeat('a', 64),
    'title' => 'Public fixture', 'text' => $argv[1] ?? 'One two three four five.',
));
