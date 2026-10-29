<?php

declare(strict_types=1);

namespace AxiomPress\Policy;

use RuntimeException;

/** Runtime metadata must come from trusted local inspection, not a publisher's manifest. */
final readonly class ImageBinding
{
    private function __construct(public Package $package, public string $image, public string $sdkImage)
    {
    }

    public static function inspect(Package $package, array $inspection, string $sdkImage): self
    {
        $image = $inspection['Id'] ?? '';
        $config = $inspection['Config'] ?? array();
        $labels = $config['Labels'] ?? array();
        if (
            !is_string($image) || !preg_match('/\Asha256:[a-f0-9]{64}\z/D', $image)
            || !preg_match('/\Asha256:[a-f0-9]{64}\z/D', $sdkImage)
            || ($labels['dev.axiompress.package.digest'] ?? null) !== $package->digest
            || ($labels['dev.axiompress.manifest.digest'] ?? null) !== $package->manifestDigest
            || ($labels['dev.axiompress.plugin.id'] ?? null) !== $package->pluginId
            || ($labels['dev.axiompress.plugin.version'] ?? null) !== $package->version
            || ($labels['dev.axiompress.sdk.image'] ?? null) !== $sdkImage
            || ($labels['dev.axiompress.profile'] ?? null) !== 'readonly-v1'
            || ($config['User'] ?? null) !== '10001:10001'
            || ($config['Entrypoint'] ?? null) !== array('php', '-n', '-d', 'memory_limit=96M', '/app/worker.php')
            || !empty($config['Volumes']) || !empty($config['Cmd'])
            || !empty($config['Healthcheck']) || !empty($config['OnBuild'])
        ) {
            throw new RuntimeException('Unapproved package/image binding.');
        }
        return new self($package, $image, $sdkImage);
    }
}
