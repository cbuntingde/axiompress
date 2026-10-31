<?php

declare(strict_types=1);

namespace AxiomPress\Policy;

use RuntimeException;

/** Publisher approval envelope binding a package digest to a trusted RSA key. */
final class PublisherApproval
{
    private const ALGORITHM = 'rsa2048-sha256';

    private string $publicKey;

    private string $signature;

    private function __construct(string $publicKey, string $signature)
    {
        $this->publicKey = $publicKey;
        $this->signature = $signature;
    }

    public static function fromEnvelope(array $envelope): self
    {
        $keys = array_keys($envelope);
        sort($keys);
        if (
            $keys !== array('algorithm', 'public_key', 'signature')
            || $envelope['algorithm'] !== self::ALGORITHM
            || !is_string($envelope['public_key']) || !is_string($envelope['signature'])
            || strlen($envelope['public_key']) > 2048 || strlen($envelope['signature']) > 1024
        ) {
            throw new RuntimeException('Malformed publisher envelope.');
        }
        $signature = base64_decode($envelope['signature'], true);
        if (!is_string($signature) || strlen($signature) !== 256) {
            throw new RuntimeException('Publisher signature is invalid.');
        }
        return new self(self::normalizeKey($envelope['public_key']), $signature);
    }

    public static function keyIdFor(string $publicKey): string
    {
        $normalized = self::normalizeKey($publicKey);
        $stripped = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $normalized);
        if (!is_string($stripped)) {
            throw new RuntimeException('Publisher public key is invalid.');
        }
        $der = base64_decode($stripped, true);
        if (!is_string($der)) {
            throw new RuntimeException('Publisher public key is invalid.');
        }
        return hash('sha256', $der);
    }

    public static function canonicalBytes(ImageBinding $binding): string
    {
        return implode("\0", array(
            $binding->package->pluginId,
            $binding->package->version,
            $binding->package->digest,
            $binding->package->manifestDigest,
            $binding->image,
            $binding->sdkImage,
        ));
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    public function signature(): string
    {
        return $this->signature;
    }

    public function keyId(): string
    {
        return self::keyIdFor($this->publicKey);
    }

    private static function normalizeKey(string $publicKey): string
    {
        if (strlen($publicKey) > 2048) {
            throw new RuntimeException('Publisher public key is invalid.');
        }
        $key = openssl_pkey_get_public($publicKey);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if (
            !is_array($details) || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
            || ($details['bits'] ?? 0) !== 2048 || !isset($details['key'])
        ) {
            throw new RuntimeException('Publisher public key must be RSA-2048.');
        }
        return $details['key'];
    }
}
