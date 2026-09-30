<?php

namespace App\Support;

use Symfony\Component\Yaml\Yaml;

/**
 * Optional JupyterLab service for an Odoo compose stack.
 *
 * The notebook shares the named volume (or relative bind) that the Odoo
 * service already mounts on a path containing "addon". It does not copy
 * that tree and it does not receive host paths, the Docker socket, or
 * instance data directories.
 */
class OdooJupyter
{
    public const IMAGE = 'quay.io/jupyter/base-notebook:python-3.12';

    public const SERVICE_NAME = 'jupyter';

    public const WORKSPACE = '/workspace/addons';

    /**
     * Used only when the addon directory is still owned by root and has no files.
     * The running notebook adopts the directory owner; it does not stay root.
     */
    public const FALLBACK_UID = '101';

    public const FALLBACK_GID = '101';

    public static function isOdooCompose(string $compose): bool
    {
        $yaml = self::parse($compose);

        return is_array($yaml) && self::odooService($yaml['services'] ?? []) !== null;
    }

    public static function inject(string $compose): string
    {
        $yaml = self::parse($compose);
        if (! is_array($yaml)) {
            return $compose;
        }

        $services = $yaml['services'] ?? null;
        if (! is_array($services) || isset($services[self::SERVICE_NAME])) {
            return $compose;
        }

        $odoo = self::odooService($services);
        if ($odoo === null) {
            return $compose;
        }

        $source = self::addonVolumeSource($odoo);
        if ($source === null) {
            return $compose;
        }

        $services[self::SERVICE_NAME] = self::serviceDefinition($source);
        $yaml['services'] = $services;

        return Yaml::dump($yaml, 8, 2);
    }

    /**
     * The service parser renames named volumes to "{uuid}_{slug}".
     * Jupyter must use that resolved source, the one Odoo actually mounts.
     *
     * @param  array<string, mixed>  $services
     * @return array<string, mixed>
     */
    public static function alignParsedServices(array $services): array
    {
        if (! isset($services[self::SERVICE_NAME]) || ! is_array($services[self::SERVICE_NAME])) {
            return $services;
        }

        $odoo = null;
        foreach ($services as $name => $service) {
            if (! is_array($service) || $name === self::SERVICE_NAME) {
                continue;
            }
            $image = strtolower((string) ($service['image'] ?? ''));
            if ($name === 'odoo' || str_starts_with($image, 'odoo:') || str_contains($image, '/odoo:')) {
                $odoo = $service;
                break;
            }
        }
        if ($odoo === null) {
            return $services;
        }

        $source = self::resolvedAddonSource($odoo['volumes'] ?? []);
        if ($source === null || ! self::resolvedSourceIsShareable($source)) {
            return $services;
        }

        $services[self::SERVICE_NAME]['volumes'] = [
            $source.':'.self::WORKSPACE,
        ];

        return $services;
    }

    /**
     * @param  array<string, mixed>  $services
     * @return array<string, mixed>|null
     */
    private static function odooService(array $services): ?array
    {
        foreach ($services as $name => $service) {
            if (! is_array($service)) {
                continue;
            }
            $image = strtolower((string) ($service['image'] ?? ''));
            $isOdoo = $name === 'odoo'
                || str_starts_with($image, 'odoo:')
                || str_contains($image, '/odoo:');
            if ($isOdoo) {
                return $service;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $odoo
     */
    private static function addonVolumeSource(array $odoo): ?string
    {
        foreach ($odoo['volumes'] ?? [] as $volume) {
            $parsed = self::parseVolume($volume);
            if ($parsed === null) {
                continue;
            }
            if (! str_contains(strtolower($parsed['target']), 'addon')) {
                continue;
            }
            if (! self::sourceIsSafe($parsed['source'])) {
                continue;
            }

            return $parsed['source'];
        }

        return null;
    }

    /**
     * @return array{source: string, target: string}|null
     */
    private static function parseVolume(mixed $volume): ?array
    {
        if (is_string($volume)) {
            $parts = explode(':', $volume);
            if (count($parts) < 2) {
                return null;
            }

            return [
                'source' => $parts[0],
                'target' => $parts[1],
            ];
        }

        if (! is_array($volume)) {
            return null;
        }

        $source = data_get($volume, 'source');
        $target = data_get($volume, 'target');
        if (! is_string($source) || ! is_string($target) || $source === '' || $target === '') {
            return null;
        }

        return [
            'source' => $source,
            'target' => $target,
        ];
    }

    private static function sourceIsSafe(string $source): bool
    {
        $source = str_replace('\\', '/', trim($source));
        $lower = strtolower($source);
        if ($source === '' || $source === '/' || str_contains($source, '..')) {
            return false;
        }
        if (str_contains($lower, 'docker.sock')) {
            return false;
        }
        if ($lower === '/root' || str_starts_with($lower, '/root/')) {
            return false;
        }
        if (str_starts_with($lower, '/data/coolify')) {
            return false;
        }
        if (str_starts_with($lower, '/var/run')) {
            return false;
        }
        if (str_starts_with($source, '/')) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private static function serviceDefinition(string $volumeSource): array
    {
        return [
            'image' => self::IMAGE,
            // Root only until gosu drops to the addon directory owner.
            'user' => '0:0',
            'working_dir' => self::WORKSPACE,
            'restart' => 'unless-stopped',
            'expose' => ['8888'],
            'entrypoint' => ['/bin/bash', '-c'],
            'environment' => [
                'SERVICE_URL_JUPYTER_8888',
                'JUPYTER_TOKEN=${SERVICE_PASSWORD_JUPYTER}',
                'HOME=/tmp',
                'JUPYTER_RUNTIME_DIR=/tmp/jupyter-runtime',
                'JUPYTER_DATA_DIR=/tmp/jupyter-data',
                'JUPYTER_CONFIG_DIR=/tmp/jupyter-config',
            ],
            'command' => self::startupScript(),
            'volumes' => [
                $volumeSource.':'.self::WORKSPACE,
            ],
        ];
    }

    public static function startupScript(): string
    {
        $target = self::WORKSPACE;
        $uid = self::FALLBACK_UID;
        $gid = self::FALLBACK_GID;

        return <<<BASH
set -euo pipefail
target={$target}
uid=\$(stat -c '%u' "\$target")
gid=\$(stat -c '%g' "\$target")
if [ "\$uid" = "0" ]; then
  sample=\$(find "\$target" -mindepth 1 -maxdepth 2 -printf '%u:%g\\n' 2>/dev/null | head -n 1 || true)
  if [ -n "\$sample" ]; then
    uid=\${sample%%:*}
    gid=\${sample##*:}
  fi
fi
if [ "\$uid" = "0" ]; then
  uid={$uid}
  gid={$gid}
fi
mkdir -p "\$JUPYTER_RUNTIME_DIR" "\$JUPYTER_DATA_DIR" "\$JUPYTER_CONFIG_DIR"
chown "\$uid:\$gid" "\$JUPYTER_RUNTIME_DIR" "\$JUPYTER_DATA_DIR" "\$JUPYTER_CONFIG_DIR"
exec gosu "\$uid:\$gid" start-notebook.py --ServerApp.root_dir="\$target"
BASH;
    }

    /**
     * @param  array<int, mixed>  $volumes
     */
    private static function resolvedAddonSource(array $volumes): ?string
    {
        foreach ($volumes as $volume) {
            $parsed = self::parseVolume($volume);
            if ($parsed === null) {
                continue;
            }
            if (! str_contains(strtolower($parsed['target']), 'addon')) {
                continue;
            }

            return $parsed['source'];
        }

        return null;
    }

    private static function resolvedSourceIsShareable(string $source): bool
    {
        $source = str_replace('\\', '/', trim($source));
        $lower = strtolower($source);
        if ($source === '' || $source === '/' || str_contains($source, '..') || str_contains($lower, 'docker.sock')) {
            return false;
        }
        if ($lower === '/root' || str_starts_with($lower, '/root/')) {
            return false;
        }
        if ($lower === '/data/coolify' || $lower === '/data/coolify/') {
            return false;
        }
        if (str_starts_with($lower, '/var/run')) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parse(string $compose): ?array
    {
        try {
            $yaml = Yaml::parse($compose);
        } catch (\Throwable) {
            return null;
        }

        return is_array($yaml) ? $yaml : null;
    }
}
