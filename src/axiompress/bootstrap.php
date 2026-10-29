<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80500 || PHP_INT_SIZE !== 8) {
    throw new RuntimeException('Native AxiomPress requires 64-bit PHP 8.5 or newer.');
}

// Fixed core sources only. No Composer plugins, WordPress bootstrap, hooks, themes or drop-ins.
require_once __DIR__ . '/Configuration.php';
require_once __DIR__ . '/ContentLimitExceeded.php';
require_once __DIR__ . '/PublicContentRepository.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Kernel.php';
require_once __DIR__ . '/ComponentRenderer.php';
