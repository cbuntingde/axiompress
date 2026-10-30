<?php

declare(strict_types=1);

use AxiomPress\Core\Jobs\Runner;
use AxiomPress\Core\PostgresConnection;

require '/app/bootstrap.php';

try {
    [$db, $site] = PostgresConnection::fromEnvironment('AXIOM_JOBS_');
    new Runner($db, $site);
    if (count($argv) === 2 && $argv[1] === 'claim') {
        $value = $db->query('SELECT axiom_media.claim()')->fetchColumn();
    } elseif (count($argv) === 2 && $argv[1] === 'finish') {
        $raw = stream_get_contents(STDIN, 3145729);
        if ($raw === false || strlen($raw) > 3145728 || !feof(STDIN)) {
            throw new RuntimeException('Media control input limit.');
        }
        $input = json_decode($raw, true, 3, JSON_THROW_ON_ERROR);
        $keys = is_array($input) ? array_keys($input) : array();
        sort($keys);
        if (
            $keys !== array('data', 'height', 'id', 'lease', 'outcome', 'width')
            || !is_string($input['id']) || !preg_match('/\A[a-f0-9]{32}\z/D', $input['id'])
            || !is_string($input['lease']) || !preg_match('/\A[a-f0-9]{32}\z/D', $input['lease'])
            || !in_array($input['outcome'], array('ready', 'invalid', 'retry'), true)
            || !is_string($input['data']) || !is_int($input['width']) || !is_int($input['height'])
        ) {
            throw new RuntimeException('Invalid media control input.');
        }
        $query = $db->prepare('SELECT axiom_media.finish(?,?,?,?,?,?)');
        $query->execute(array($input['id'], $input['lease'], $input['outcome'],
            $input['data'], $input['width'], $input['height']));
        $value = $query->fetchColumn();
    } else {
        throw new RuntimeException('Fixed media control operation required.');
    }
    json_decode($value, true, 8, JSON_THROW_ON_ERROR);
    fwrite(STDOUT, $value);
} catch (Throwable $error) {
    fwrite(STDERR, 'Media controller unavailable; diagnostic=' . bin2hex(random_bytes(12)) . "\n");
    exit(1);
}
