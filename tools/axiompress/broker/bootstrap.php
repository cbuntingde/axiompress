<?php

declare(strict_types=1);

// Trusted library bootstrap only: no core bootstrap and no worker/package includes.
require_once __DIR__ . '/../protocol/ProtocolError.php';
require_once __DIR__ . '/../protocol/Protocol.php';
require_once __DIR__ . '/BrokerError.php';
require_once __DIR__ . '/InvocationRegistry.php';
require_once __DIR__ . '/ContentBroker.php';
require_once __DIR__ . '/WordPressContentAdapter.php';
