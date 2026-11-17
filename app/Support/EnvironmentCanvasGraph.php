<?php

namespace App\Support;

use App\Models\Environment;
use App\Models\EnvironmentCanvasLayout;
use App\Models\Service;
use App\Models\ServiceApplication;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EnvironmentCanvasGraph
{
    public const NODE_W = 280;

    public const NODE_H = 104;

    /**
     * Build the environment canvas graph from Coolify models + saved layout.
     *
     * @return array{
     *     nodes: list<array<string, mixed>>,
     *     edges: list<array<string, mixed>>,
     *     meta: array{environment: string, project: string, count: int}
     * }
     */
    public static function build(Environment $environment): array
    {
        $environment->loadMissing([
            'project',
            'services.applications.persistentStorages',
            'services.databases.persistentStorages',
            'applications',
            'postgresqls',
            'redis',
            'mongodbs',
            'mysqls',
            'mariadbs',
            'keydbs',
            'dragonflies',
            'clickhouses',
            'sqlites',
            'canvasLayout',
        ]);

        $nodes = collect();
        $edges = collect();

        foreach ($environment->services as $service) {
            $service->setRelation('environment', $environment);
            self::appendServiceCluster($service, $nodes, $edges);
        }

        foreach ($environment->applications as $application) {
            if ($application->is_odoo_addons) {
                continue;
            }
            $key = 'application:'.$application->uuid;
            $nodes->push(self::withIcon([
                'id' => $key,
                'kind' => 'application',
                'label' => (string) $application->name,
                'status' => self::normalizeStatus((string) ($application->status ?? '')),
                'status_label' => self::statusLabel((string) ($application->status ?? '')),
                'href' => route('project.application.configuration', [
                    'project_uuid' => $environment->project->uuid,
                    'environment_uuid' => $environment->uuid,
                    'application_uuid' => $application->uuid,
                ]),
                'meta' => trim((string) ($application->fqdn ?? '')) ?: __('Application'),
                'ports' => ['in' => true, 'out' => true],
            ], 'application', (string) ($application->build_pack ?? '')));
        }

        foreach ($environment->databases() as $database) {
            $uuid = (string) ($database->uuid ?? '');
            if ($uuid === '') {
                continue;
            }
            $kindKey = Str::of(class_basename($database))->lower()->replace('standalone', '')->toString();
            $key = 'database:'.$uuid;
            $nodes->push(self::withIcon([
                'id' => $key,
                'kind' => 'database',
                'label' => (string) ($database->name ?? class_basename($database)),
                'status' => self::normalizeStatus((string) ($database->status ?? '')),
                'status_label' => self::statusLabel((string) ($database->status ?? '')),
                'href' => route('project.database.configuration', [
                    'project_uuid' => $environment->project->uuid,
                    'environment_uuid' => $environment->uuid,
                    'database_uuid' => $uuid,
                ]),
                'meta' => class_basename($database),
                'ports' => ['in' => true, 'out' => false],
            ], 'database', $kindKey));
        }

        $layout = $environment->canvasLayout;
        $positions = $layout instanceof EnvironmentCanvasLayout ? $layout->positionsMap() : [];
        $placed = self::applyPositions($nodes->values(), $positions);

        return [
            'nodes' => $placed->all(),
            'edges' => $edges->values()->all(),
            'meta' => [
                'environment' => (string) $environment->name,
                'project' => (string) ($environment->project?->name ?? ''),
                'count' => $placed->count(),
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $nodes
     * @param  Collection<int, array<string, mixed>>  $edges
     */
    private static function appendServiceCluster(Service $service, Collection $nodes, Collection $edges): void
    {
        $serviceKey = 'service:'.$service->uuid;
        $isOdoo = $service->supportsOdooJupyter() || OdooJupyter::isOdooCompose((string) $service->docker_compose_raw);
        $type = (string) ($service->service_type ?? '');
        if ($type === '' && $isOdoo) {
            $type = 'odoo';
        }
        $projectUuid = $service->environment->project->uuid;
        $environmentUuid = $service->environment->uuid;
        $href = $isOdoo
            ? route('project.service.deployments', [
                'project_uuid' => $projectUuid,
                'environment_uuid' => $environmentUuid,
                'service_uuid' => $service->uuid,
            ])
            : route('project.service.configuration', [
                'project_uuid' => $projectUuid,
                'environment_uuid' => $environmentUuid,
                'service_uuid' => $service->uuid,
            ]);

        $logsHref = route('project.service.logs', array_filter([
            'project_uuid' => $projectUuid,
            'environment_uuid' => $environmentUuid,
            'service_uuid' => $service->uuid,
            'only' => $isOdoo ? 'odoo' : null,
        ]));
        $monitorHref = $isOdoo ? OdooMonitor::urlFor($service) : null;

        $iconHint = $type !== '' ? $type : self::guessTypeFromCompose((string) $service->docker_compose_raw);
        $nodes->push(self::withIcon([
            'id' => $serviceKey,
            'kind' => $isOdoo ? 'odoo' : 'service',
            'label' => (string) $service->name,
            'status' => self::normalizeStatus((string) ($service->status ?? '')),
            'status_label' => self::statusLabel((string) ($service->status ?? '')),
            'href' => $href,
            'logs_href' => $logsHref,
            'monitor_href' => $monitorHref,
            'meta' => $isOdoo ? 'Odoo' : ($type !== '' ? Str::headline($type) : __('Service')),
            'ports' => ['in' => true, 'out' => true],
        ], $isOdoo ? 'odoo' : 'service', $iconHint));

        foreach ($service->applications as $application) {
            self::appendDomainNodes($application, $serviceKey, $nodes, $edges);
            self::appendVolumeNodes($application->persistentStorages ?? collect(), $serviceKey, $nodes, $edges, $href);
        }

        foreach ($service->databases as $database) {
            $dbKey = 'service-db:'.$database->uuid;
            $dbType = Str::of((string) $database->name)->lower()->toString();
            $nodes->push(self::withIcon([
                'id' => $dbKey,
                'kind' => 'database',
                'label' => (string) $database->name,
                'status' => self::normalizeStatus((string) ($database->status ?? '')),
                'status_label' => self::statusLabel((string) ($database->status ?? '')),
                'href' => $href,
                'meta' => __('Compose database'),
                'ports' => ['in' => true, 'out' => false],
            ], 'database', $dbType));
            $edges->push([
                'id' => $serviceKey.'->'.$dbKey,
                'from' => $serviceKey,
                'to' => $dbKey,
                'label' => 'db',
            ]);
            self::appendVolumeNodes($database->persistentStorages ?? collect(), $dbKey, $nodes, $edges, $href);
        }

        foreach (self::namedVolumesFromCompose((string) $service->docker_compose_raw) as $volumeName) {
            $volKey = 'compose-vol:'.$service->uuid.':'.$volumeName;
            if ($nodes->contains(fn (array $node): bool => $node['id'] === $volKey)) {
                continue;
            }
            $nodes->push(self::withIcon([
                'id' => $volKey,
                'kind' => 'volume',
                'label' => $volumeName,
                'status' => 'idle',
                'status_label' => __('Mounted'),
                'href' => $href,
                'meta' => __('Persistent volume'),
                'ports' => ['in' => true, 'out' => false],
            ], 'volume'));
            $edges->push([
                'id' => $serviceKey.'->'.$volKey,
                'from' => $serviceKey,
                'to' => $volKey,
                'label' => 'mount',
            ]);
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $nodes
     * @param  Collection<int, array<string, mixed>>  $edges
     */
    private static function appendDomainNodes(ServiceApplication $application, string $serviceKey, Collection $nodes, Collection $edges): void
    {
        $fqdns = collect(explode(',', (string) ($application->fqdn ?? '')))
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique()
            ->values();

        foreach ($fqdns as $index => $fqdn) {
            $host = (string) (parse_url($fqdn, PHP_URL_HOST) ?: Str::of($fqdn)->after('://')->before('/'));
            if ($host === '') {
                $host = $fqdn;
            }
            $domKey = 'domain:'.$application->uuid.':'.$index;
            $nodes->push(self::withIcon([
                'id' => $domKey,
                'kind' => 'domain',
                'label' => $host,
                'status' => 'idle',
                'status_label' => 'HTTPS',
                'href' => $fqdn,
                'meta' => __('Public domain'),
                'external' => true,
                'ports' => ['in' => false, 'out' => true],
            ], 'domain'));
            $edges->push([
                'id' => $domKey.'->'.$serviceKey,
                'from' => $domKey,
                'to' => $serviceKey,
                'label' => '443',
            ]);
        }
    }

    /**
     * @param  Collection<int, mixed>  $storages
     * @param  Collection<int, array<string, mixed>>  $nodes
     * @param  Collection<int, array<string, mixed>>  $edges
     */
    private static function appendVolumeNodes(Collection $storages, string $parentKey, Collection $nodes, Collection $edges, ?string $href = null): void
    {
        foreach ($storages as $storage) {
            $id = (string) ($storage->id ?? $storage->name ?? uniqid('vol', true));
            $volKey = 'volume:'.$id;
            if ($nodes->contains(fn (array $node): bool => $node['id'] === $volKey)) {
                $edges->push([
                    'id' => $parentKey.'->'.$volKey,
                    'from' => $parentKey,
                    'to' => $volKey,
                    'label' => 'mount',
                ]);

                continue;
            }
            $nodes->push(self::withIcon([
                'id' => $volKey,
                'kind' => 'volume',
                'label' => (string) ($storage->name ?? $storage->mount_path ?? 'volume'),
                'status' => 'idle',
                'status_label' => __('Mounted'),
                'href' => $href,
                'meta' => __('Persistent volume'),
                'ports' => ['in' => true, 'out' => false],
            ], 'volume'));
            $edges->push([
                'id' => $parentKey.'->'.$volKey,
                'from' => $parentKey,
                'to' => $volKey,
                'label' => 'mount',
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private static function namedVolumesFromCompose(string $compose): array
    {
        if ($compose === '') {
            return [];
        }

        if (preg_match('/^volumes:\s*\n((?:[ \t]+.+\n?)+)/mi', $compose, $block)) {
            preg_match_all('/^\s{2}([A-Za-z0-9._-]+):/m', $block[1], $named);

            return array_values(array_unique($named[1] ?? []));
        }

        preg_match_all('/-\s+([A-Za-z0-9._-]+):\/[^\s]+/', $compose, $namedMounts);

        return array_values(array_unique($namedMounts[1] ?? []));
    }

    private static function guessTypeFromCompose(string $compose): string
    {
        $lower = strtolower($compose);
        foreach (['odoo', 'postgres', 'postgresql', 'mysql', 'mariadb', 'mongo', 'redis', 'keydb', 'dragonfly', 'clickhouse', 'nginx', 'minio'] as $needle) {
            if (str_contains($lower, $needle)) {
                return $needle === 'postgres' ? 'postgresql' : $needle;
            }
        }

        return '';
    }

    /**
     * @return array{icon: ?string, glyph: ?string}
     */
    private static function iconMeta(string $kind, string $hint = ''): array
    {
        $hint = Str::of($hint)->lower()->replace(['standalone', '_', ' '], '')->toString();

        if ($kind === 'domain') {
            return ['icon' => null, 'glyph' => 'globe'];
        }
        if ($kind === 'volume') {
            return ['icon' => null, 'glyph' => 'disk'];
        }

        $candidates = match ($kind) {
            'odoo' => ['svgs/odoo.svg'],
            'database' => array_values(array_filter([
                $hint !== '' ? "svgs/{$hint}.svg" : null,
                $hint === 'postgresql' ? 'svgs/postgres.svg' : null,
                $hint === 'postgres' ? 'svgs/postgresql.svg' : null,
                in_array($hint, ['mongo', 'mongodb'], true) ? 'svgs/mongodb.svg' : null,
                'svgs/postgresql.svg',
                'svgs/postgres.svg',
                'svgs/mysql.svg',
                'svgs/mariadb.svg',
                'svgs/mongodb.svg',
                'svgs/redis.svg',
                'svgs/default.webp',
            ])),
            'service', 'application' => array_values(array_filter([
                $hint !== '' ? "svgs/{$hint}.svg" : null,
                $hint !== '' ? "svgs/{$hint}.png" : null,
                $hint !== '' ? "svgs/{$hint}.webp" : null,
                'svgs/docker.svg',
                'svgs/default.webp',
            ])),
            default => ['svgs/default.webp'],
        };

        foreach ($candidates as $path) {
            if (is_string($path) && $path !== '' && file_exists(public_path($path))) {
                return ['icon' => ServiceTemplateCatalog::logoUrl($path), 'glyph' => null];
            }
        }

        return ['icon' => ServiceTemplateCatalog::logoUrl('svgs/default.webp'), 'glyph' => null];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private static function withIcon(array $base, string $kind, string $hint = ''): array
    {
        return array_merge($base, self::iconMeta($kind, $hint));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $nodes
     * @param  array<string, array{x: float, y: float}>  $positions
     * @return Collection<int, array<string, mixed>>
     */
    private static function applyPositions(Collection $nodes, array $positions): Collection
    {
        $defaults = self::defaultPositions($nodes);

        return $nodes->map(function (array $node, int $index) use ($positions, $defaults): array {
            $id = (string) $node['id'];
            $fallback = $defaults[$id] ?? ['x' => 80.0 + ($index % 3) * 340.0, 'y' => 80.0 + intdiv($index, 3) * 160.0];
            $node['x'] = (float) ($positions[$id]['x'] ?? $fallback['x']);
            $node['y'] = (float) ($positions[$id]['y'] ?? $fallback['y']);

            return $node;
        });
    }

    /**
     * Horizontal flow layout: domains → services → data (n8n / Railway style).
     *
     * @param  Collection<int, array<string, mixed>>  $nodes
     * @return array<string, array{x: float, y: float}>
     */
    private static function defaultPositions(Collection $nodes): array
    {
        $lanes = [
            'domain' => 0,
            'odoo' => 1,
            'service' => 1,
            'application' => 1,
            'database' => 2,
            'volume' => 2,
        ];
        $counters = [];
        $out = [];
        foreach ($nodes as $node) {
            $kind = (string) ($node['kind'] ?? 'service');
            $lane = $lanes[$kind] ?? 1;
            $counters[$lane] = ($counters[$lane] ?? 0);
            $out[(string) $node['id']] = [
                'x' => 72.0 + $lane * 360.0,
                'y' => 72.0 + $counters[$lane] * 150.0,
            ];
            $counters[$lane]++;
        }

        return $out;
    }

    private static function normalizeStatus(string $status): string
    {
        $status = strtolower($status);
        if ($status === '' || $status === 'unknown') {
            return 'idle';
        }
        if (str_contains($status, 'running') || str_contains($status, 'healthy')) {
            return 'running';
        }
        if (str_contains($status, 'degraded') || str_contains($status, 'unhealthy') || str_contains($status, 'restarting')) {
            return 'degraded';
        }
        if (str_contains($status, 'exited') || str_contains($status, 'stopped') || str_contains($status, 'dead')) {
            return 'stopped';
        }
        if (str_contains($status, 'progress') || str_contains($status, 'starting') || str_contains($status, 'building')) {
            return 'building';
        }

        return 'idle';
    }

    private static function statusLabel(string $status): string
    {
        return match (self::normalizeStatus($status)) {
            'running' => __('Running'),
            'building' => __('Building'),
            'degraded' => __('Degraded'),
            'stopped' => __('Stopped'),
            default => __('Idle'),
        };
    }
}
