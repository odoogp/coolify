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
            'project.odooProfile',
            'odooBranch',
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
            $appParams = [
                'project_uuid' => $environment->project->uuid,
                'environment_uuid' => $environment->uuid,
                'application_uuid' => $application->uuid,
            ];
            $nodes->push(self::withIcon([
                'id' => $key,
                'kind' => 'application',
                'label' => (string) $application->name,
                'status' => self::normalizeStatus((string) ($application->status ?? '')),
                'status_label' => self::statusLabel((string) ($application->status ?? '')),
                'panels' => ['logs', 'github'],
                'open_href' => filled($application->fqdn) ? (string) str($application->fqdn)->explode(',')->first() : null,
                'github' => [
                    'repository' => null,
                    'branch' => null,
                    'linked' => false,
                    'source_href' => route('project.application.source', $appParams),
                ],
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
                'panels' => ['logs'],
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
     * One node per stack unit (compose app / DB / GitHub / domain / volume).
     * Never collapse the Coolify Service into a single config blob.
     *
     * @param  Collection<int, array<string, mixed>>  $nodes
     * @param  Collection<int, array<string, mixed>>  $edges
     */
    private static function appendServiceCluster(Service $service, Collection $nodes, Collection $edges): void
    {
        $isOdoo = $service->supportsOdooJupyter() || OdooJupyter::isOdooCompose((string) $service->docker_compose_raw);
        $clientOnly = $isOdoo && ! isInstanceOwner();
        $environment = $service->environment;
        $projectUuid = $environment->project->uuid;
        $environmentUuid = $environment->uuid;
        $configParams = [
            'project_uuid' => $projectUuid,
            'environment_uuid' => $environmentUuid,
            'service_uuid' => $service->uuid,
        ];
        $server = $service->server;
        $sentinelEnabled = $server && $server->isSentinelEnabled();
        $monitorUrl = (! $sentinelEnabled && $isOdoo) ? OdooMonitor::urlFor($service) : null;
        $hubKey = null;
        $stackAppKeys = [];

        foreach ($service->applications as $application) {
            $appName = (string) $application->name;
            if ($clientOnly && ! OdooGit::clientSeesLog($appName)) {
                continue;
            }
            // Prefer Sentinel metrics — hide legacy Beszel monitor nodes when Sentinel is on.
            if ($sentinelEnabled && str_contains(strtolower($appName), 'monitor')) {
                continue;
            }

            $appKey = 'stack-app:'.$application->uuid;
            $stackAppKeys[] = $appKey;
            $kind = self::stackAppKind($appName, $isOdoo);
            $container = $appName.'-'.$service->uuid;
            $panels = ['logs', 'metrics'];
            if ($kind === 'odoo' && filled($monitorUrl)) {
                $panels[] = 'monitor';
            }
            if ($kind === 'monitor' && filled($monitorUrl)) {
                $panels[] = 'monitor';
            }
            $rules = self::stackRulesFor($kind, $isOdoo);

            $openHref = null;
            if ($kind === 'odoo' && $service->canOpenPublicSite()) {
                $openHref = route('project.service.odoo.enter', $configParams);
            } elseif (filled($application->fqdn) && $kind !== 'monitor') {
                $openHref = (string) str($application->fqdn)->explode(',')->map(fn ($v) => trim((string) $v))->first();
            }

            $nodes->push(self::withIcon([
                'id' => $appKey,
                'kind' => $kind,
                'label' => $appName,
                'status' => self::normalizeStatus((string) ($application->status ?? $service->status ?? '')),
                'status_label' => self::statusLabel((string) ($application->status ?? $service->status ?? '')),
                'service_uuid' => $service->uuid,
                'container' => $container,
                'panels' => $panels,
                'rules' => $rules,
                'monitor_url' => in_array('monitor', $panels, true) ? $monitorUrl : null,
                'open_href' => $openHref,
                'meta' => match ($kind) {
                    'odoo' => 'Odoo',
                    'monitor' => __('Monitor'),
                    'jupyter' => 'Jupyter',
                    default => Str::headline($appName),
                },
                'ports' => ['in' => true, 'out' => true],
            ], $kind, $appName));

            if ($kind === 'odoo' || ($hubKey === null && $kind !== 'monitor' && $kind !== 'jupyter')) {
                $hubKey = $appKey;
            }

            self::appendDomainNodes($application, $appKey, $nodes, $edges);
            self::appendVolumeNodes($application->persistentStorages ?? collect(), $appKey, $nodes, $edges);
        }

        if ($hubKey === null && $stackAppKeys !== []) {
            $hubKey = $stackAppKeys[0];
        }

        if ($isOdoo) {
            $githubKey = 'github:'.$service->uuid;
            $environment->loadMissing('odooBranch', 'project.odooProfile');
            $repo = (string) ($environment->odooBranch?->git_repository
                ?: $environment->project?->odooProfile?->git_repository
                ?: '');
            $branch = (string) ($environment->odooBranch?->git_branch ?: '');
            $linked = $repo !== '' && $branch !== '';
            $nodes->push(self::withIcon([
                'id' => $githubKey,
                'kind' => 'github',
                'label' => $linked ? ($repo.($branch !== '' ? ':'.$branch : '')) : __('GitHub'),
                'status' => $linked ? 'running' : 'idle',
                'status_label' => $linked ? __('Linked') : __('Not linked'),
                'service_uuid' => $service->uuid,
                'panels' => ['github'],
                'github' => [
                    'repository' => $repo !== '' ? $repo : null,
                    'branch' => $branch !== '' ? $branch : null,
                    'linked' => $linked,
                ],
                'meta' => __('Source'),
                'ports' => ['in' => false, 'out' => true],
            ], 'github'));

            if ($hubKey !== null) {
                $edges->push([
                    'id' => $githubKey.'->'.$hubKey,
                    'from' => $githubKey,
                    'to' => $hubKey,
                    'label' => 'git',
                ]);
            }
        }

        foreach ($service->databases as $database) {
            $dbName = (string) $database->name;
            if ($clientOnly && ! OdooGit::clientSeesLog($dbName)) {
                continue;
            }
            $dbKey = 'stack-db:'.$database->uuid;
            $nodes->push(self::withIcon([
                'id' => $dbKey,
                'kind' => 'database',
                'label' => $dbName,
                'status' => self::normalizeStatus((string) ($database->status ?? '')),
                'status_label' => self::statusLabel((string) ($database->status ?? '')),
                'service_uuid' => $service->uuid,
                'container' => $dbName.'-'.$service->uuid,
                'panels' => ['logs', 'metrics'],
                'rules' => self::stackRulesFor('database', $isOdoo),
                'meta' => __('Database'),
                'ports' => ['in' => true, 'out' => false],
            ], 'database', $dbName));

            if ($hubKey !== null) {
                $edges->push([
                    'id' => $hubKey.'->'.$dbKey,
                    'from' => $hubKey,
                    'to' => $dbKey,
                    'label' => 'db',
                ]);
            }
            self::appendVolumeNodes($database->persistentStorages ?? collect(), $dbKey, $nodes, $edges);
        }

        $volumeParent = $hubKey;
        if ($volumeParent !== null) {
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
                    'meta' => __('Persistent volume'),
                    'ports' => ['in' => true, 'out' => false],
                ], 'volume'));
                $edges->push([
                    'id' => $volumeParent.'->'.$volKey,
                    'from' => $volumeParent,
                    'to' => $volKey,
                    'label' => 'mount',
                ]);
            }
        }
    }

    private static function stackAppKind(string $name, bool $isOdooStack): string
    {
        $lower = strtolower($name);
        if (str_contains($lower, 'jupyter')) {
            return 'jupyter';
        }
        if ($lower === 'monitor' || str_contains($lower, 'monitor')) {
            return 'monitor';
        }
        if ($isOdooStack && str_contains($lower, 'odoo')) {
            return 'odoo';
        }

        return 'service';
    }

    /**
     * @return list<string>
     */
    private static function stackRulesFor(string $kind, bool $isOdooStack): array
    {
        if (! $isOdooStack) {
            return [];
        }

        return match ($kind) {
            'odoo' => [
                __('Requires PostgreSQL in the same stack.'),
                __('Public HTTPS terminates on this service.'),
                __('Optional GitHub addons sync into the extra-addons volume.'),
            ],
            'database' => [
                __('Holds the Odoo database for this environment.'),
                __('Must stay on the same Docker network as Odoo.'),
            ],
            'jupyter' => [
                __('Shares the Odoo addons volume for editing.'),
            ],
            default => [],
        };
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
    private static function appendVolumeNodes(Collection $storages, string $parentKey, Collection $nodes, Collection $edges): void
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
        if ($kind === 'github') {
            return ['icon' => null, 'glyph' => 'github'];
        }
        if ($kind === 'monitor') {
            return ['icon' => null, 'glyph' => 'monitor'];
        }

        $candidates = match ($kind) {
            'odoo' => ['svgs/odoo.svg'],
            'jupyter' => array_values(array_filter([
                'svgs/jupyter.svg',
                'svgs/jupyter.png',
                'svgs/default.webp',
            ])),
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
            'github' => 0,
            'domain' => 0,
            'odoo' => 1,
            'service' => 1,
            'application' => 1,
            'jupyter' => 1,
            'monitor' => 1,
            'database' => 2,
            'volume' => 3,
        ];
        $counters = [];
        $out = [];
        foreach ($nodes as $node) {
            $kind = (string) ($node['kind'] ?? 'service');
            $lane = $lanes[$kind] ?? 1;
            $counters[$lane] = ($counters[$lane] ?? 0);
            $out[(string) $node['id']] = [
                'x' => 72.0 + $lane * 320.0,
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
