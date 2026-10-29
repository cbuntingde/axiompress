<?php

declare(strict_types=1);

namespace AxiomPress\Core;

use RuntimeException;

/** Environment data only. Never include wp-config.php or executable extension configuration. */
final readonly class Configuration
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $user,
        public string $passwordFile,
        public string $publicView,
        public int $site
    ) {
        if (
            !preg_match('/\A[a-zA-Z0-9.-]{1,253}\z/D', $host) || $port < 1 || $port > 65535
            || !preg_match('/\A[a-zA-Z0-9_]{1,64}\z/D', $database)
            || !preg_match('/\A[a-zA-Z0-9_]{1,32}\z/D', $user)
            || !preg_match('/\Aaxiompress_public_[a-z0-9_]{1,32}\z/D', $publicView)
            || $site < 1 || !str_starts_with($passwordFile, '/')
        ) {
            throw new RuntimeException('Invalid native core configuration.');
        }
    }

    public static function fromEnvironment(): self
    {
        $required = static function (string $key): string {
            $value = getenv($key);
            if ($value === false || $value === '') {
                throw new RuntimeException('Missing native core configuration.');
            }
            return $value;
        };
        $integer = static function (string $value): int {
            if (!preg_match('/\A[1-9][0-9]{0,9}\z/D', $value) || (float) $value > PHP_INT_MAX) {
                throw new RuntimeException('Invalid native core integer.');
            }
            return (int) $value;
        };
        return new self(
            $required('AXIOM_DB_HOST'),
            $integer($required('AXIOM_DB_PORT')),
            $required('AXIOM_DB_NAME'),
            $required('AXIOM_DB_USER'),
            $required('AXIOM_DB_PASSWORD_FILE'),
            $required('AXIOM_PUBLIC_VIEW'),
            $integer($required('AXIOM_SITE_ID'))
        );
    }

    public function password(): string
    {
        if (is_link($this->passwordFile) || !is_file($this->passwordFile)) {
            throw new RuntimeException('Native core secret unavailable.');
        }
        $stream = fopen($this->passwordFile, 're');
        if ($stream === false) {
            throw new RuntimeException('Native core secret unavailable.');
        }
        try {
            $password = stream_get_contents($stream, 257);
            if ($password === false || strlen($password) > 256 || strlen($password) < 16) {
                throw new RuntimeException('Invalid native core secret.');
            }
            return $password;
        } finally {
            fclose($stream);
        }
    }
}
