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
            'orphanVolumes',
            'canvasLayout',
        ]);

        $nodes = collect();
        $edges = collect();

        foreach ($environment->services as $service) {
            $service->setRelation('environment', $environment);
            self::appendServiceCluster($service, $nodes, $edges);
        }

        self::appendOrphanVolumes($environment, $nodes);

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

        self::appendAgentNode($environment, $nodes, $edges);

        $layout = $environment->canvasLayout;
        $positions = $layout instanceof EnvironmentCanvasLayout ? $layout->positionsMap() : [];
        if ($layout instanceof EnvironmentCanvasLayout) {
            foreach ($layout->connectionsList() as $connection) {
                if ($nodes->contains(fn (array $node): bool => $node['id'] === $connection['from'])
                    && $nodes->contains(fn (array $node): bool => $node['id'] === $connection['to'])) {
                    $edges->push([
                        'id' => $connection['id'],
                        'from' => $connection['from'],
                        'to' => $connection['to'],
                        'label' => $connection['label'] ?? 'link',
                    ]);
                }
            }
        }
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
    private static function appendAgentNode(Environment $environment, Collection $nodes, Collection $edges): void
    {
        $project = $environment->project;
        if (! $project) {
            return;
        }
        $project->loadMissing('agent');
        $agent = $project->agent;
        // Agent is opt-in: only appear after the user adds one on the canvas.
        if ($agent === null) {
            return;
        }
        $agentKey = 'agent:'.$project->uuid;
        $ready = $agent->isReady();
        $nodes->push(self::withIcon([
            'id' => $agentKey,
            'kind' => 'agent',
            'label' => (string) ($agent->name ?: __('AI agent')),
            'status' => $ready ? 'running' : 'idle',
            'status_label' => $ready ? __('Ready') : __('Configure'),
            'meta' => __('Access only via canvas connections'),
            'panels' => ['chat', 'settings'],
            'project_uuid' => $project->uuid,
            'ports' => ['in' => true, 'out' => true],
        ], 'agent'));

        foreach ($agent->connectedNodeIds() as $targetId) {
            if (! $nodes->contains(fn (array $node): bool => $node['id'] === $targetId)) {
                continue;
            }
            $edges->push([
                'id' => $agentKey.'->'.$targetId,
                'from' => $agentKey,
                'to' => $targetId,
                'label' => 'context',
            ]);
        }
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
            $panels = ['logs', 'metrics', 'config'];
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
                'config_href' => route('project.service.configuration', $configParams),
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

            self::appendDomainNodes(
                $application,
                $appKey,
                $nodes,
                $edges,
                (string) $service->uuid,
                route('project.service.domains', $configParams),
            );
            self::appendVolumeNodes(
                $application->persistentStorages ?? collect(),
                $appKey,
                $nodes,
                $edges,
                (string) $service->uuid,
                route('project.service.configuration', $configParams),
            );
        }

        if ($hubKey === null && $stackAppKeys !== []) {
            $hubKey = $stackAppKeys[0];
        }

        // GitHub is optional, but always emit a node for Odoo so users can
        // connect / fix an incomplete link from the canvas (not buried in services).
        if ($isOdoo) {
            $environment->loadMissing('odooBranch', 'project.odooProfile');
            $profile = $environment->project?->odooProfile;
            $repo = (string) ($environment->odooBranch?->git_repository
                ?: $profile?->git_repository
                ?: '');
            $branch = (string) ($environment->odooBranch?->git_branch ?: '');
            $appId = (int) ($profile?->github_app_id ?? 0);
            $linked = $repo !== '' && $branch !== '' && $appId > 0;
            // Half-linked: repo/branch without app, or app without a usable tip.
            $incomplete = ! $linked && ($repo !== '' || $branch !== '' || $appId > 0);
            $githubKey = 'github:'.$service->uuid;
            $label = $linked
                ? $repo.($branch !== '' ? ':'.$branch : '')
                : ($incomplete && $repo !== '' ? $repo : __('GitHub'));
            $nodes->push(self::withIcon([
                'id' => $githubKey,
                'kind' => 'github',
                'label' => $label,
                'status' => $linked ? 'running' : ($incomplete ? 'degraded' : 'exited'),
                'status_label' => $linked ? __('Linked') : ($incomplete ? __('Needs setup') : __('Connect')),
                'service_uuid' => $service->uuid,
                'panels' => ['github', 'config'],
                'config_href' => route('project.service.configuration', array_merge($configParams, ['odooPanel' => 'github'])),
                'github' => [
                    'repository' => $repo !== '' ? $repo : null,
                    'branch' => $branch !== '' ? $branch : null,
                    'linked' => $linked,
                    'incomplete' => $incomplete,
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
                'panels' => ['logs', 'metrics', 'config'],
                'config_href' => route('project.service.configuration', $configParams),
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
            self::appendVolumeNodes(
                $database->persistentStorages ?? collect(),
                $dbKey,
                $nodes,
                $edges,
                (string) $service->uuid,
                route('project.service.configuration', $configParams),
            );
        }

        $volumeParent = $hubKey;
        if ($volumeParent !== null) {
            $configHref = route('project.service.configuration', $configParams);
            foreach (self::namedVolumesFromCompose((string) $service->docker_compose_raw) as $volumeName) {
                $volKey = 'compose-vol:'.$service->uuid.':'.$volumeName;
                self::attachVolumeSummary($nodes, $volumeParent, [
                    'id' => $volKey,
                    'label' => $volumeName,
                    'mount_path' => null,
                    'service_uuid' => $service->uuid,
                    'config_href' => $configHref,
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
    private static function appendDomainNodes(
        ServiceApplication $application,
        string $serviceKey,
        Collection $nodes,
        Collection $edges,
        ?string $serviceUuid = null,
        ?string $domainsHref = null,
    ): void {
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
            $scheme = strtolower((string) (parse_url($fqdn, PHP_URL_SCHEME) ?: 'https'));
            // Domains stay on the service card summary / inspector — not as
            // separate canvas nodes (avoids long FQDN chips and edge spaghetti).
            $domKey = 'domain:'.$application->uuid.':'.$index;
            self::attachDomainSummary($nodes, $serviceKey, [
                'id' => $domKey,
                'label' => $host,
                'fqdn' => $fqdn,
                'scheme' => $scheme,
                'config_href' => $domainsHref,
                'service_uuid' => $serviceUuid,
            ]);
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $nodes
     * @param  array{id: string, label: string, fqdn: string}  $summary
     */
    private static function attachDomainSummary(Collection $nodes, string $parentKey, array $summary): void
    {
        $index = $nodes->search(fn (array $node): bool => ($node['id'] ?? null) === $parentKey);
        if ($index === false) {
            return;
        }

        $node = $nodes->get($index);
        if (! is_array($node)) {
            return;
        }

        $domains = collect($node['domains'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values();
        if ($domains->contains(fn (array $row): bool => ($row['id'] ?? null) === $summary['id'])) {
            return;
        }

        $node['domains'] = $domains->push($summary)->values()->all();
        $nodes->put($index, $node);
    }

    /**
     * Volumes kept after a service delete (resource morph = Environment).
     *
     * @param  Collection<int, array<string, mixed>>  $nodes
     */
    private static function appendOrphanVolumes(Environment $environment, Collection $nodes): void
    {
        foreach ($environment->orphanVolumes as $storage) {
            $id = (string) ($storage->id ?? uniqid('orphan', true));
            $volKey = 'volume:'.$id;
            if ($nodes->contains(fn (array $node): bool => $node['id'] === $volKey)) {
                continue;
            }
            $nodes->push(self::withIcon([
                'id' => $volKey,
                'kind' => 'volume',
                'label' => (string) ($storage->name ?? 'volume'),
                'status' => 'idle',
                'status_label' => __('Orphan'),
                'meta' => __('Orphan volume — delete separately'),
                'parent_id' => null,
                'orphan' => true,
                'service_uuid' => null,
                'storage_id' => is_numeric($storage->id ?? null) ? (int) $storage->id : null,
                'mount_path' => filled($storage->mount_path ?? null) ? (string) $storage->mount_path : null,
                'panels' => ['settings'],
                'ports' => ['in' => false, 'out' => false],
            ], 'volume'));
        }
    }

    /**
     * @param  Collection<int, mixed>  $storages
     * @param  Collection<int, array<string, mixed>>  $nodes
     * @param  Collection<int, array<string, mixed>>  $edges
     */
    private static function appendVolumeNodes(
        Collection $storages,
        string $parentKey,
        Collection $nodes,
        Collection $edges,
        ?string $serviceUuid = null,
        ?string $configHref = null,
    ): void {
        foreach ($storages as $storage) {
            $id = (string) ($storage->id ?? $storage->name ?? uniqid('vol', true));
            $volKey = 'volume:'.$id;
            $label = (string) ($storage->name ?? $storage->mount_path ?? 'volume');
            $mountPath = filled($storage->mount_path ?? null) ? (string) $storage->mount_path : null;
            // Attached volumes live in the inspector, not as canvas satellites.
            // Orphan volumes (no parent service) still get their own nodes.
            self::attachVolumeSummary($nodes, $parentKey, [
                'id' => $volKey,
                'label' => $label,
                'mount_path' => $mountPath,
                'storage_id' => is_numeric($storage->id ?? null) ? (int) $storage->id : null,
                'service_uuid' => $serviceUuid,
                'config_href' => $configHref,
            ]);
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $nodes
     * @param  array{id: string, label: string, mount_path: ?string}  $summary
     */
    private static function attachVolumeSummary(Collection $nodes, string $parentKey, array $summary): void
    {
        $index = $nodes->search(fn (array $node): bool => ($node['id'] ?? null) === $parentKey);
        if ($index === false) {
            return;
        }

        $node = $nodes->get($index);
        if (! is_array($node)) {
            return;
        }

        $volumes = collect($node['volumes'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->values();
        if ($volumes->contains(fn (array $row): bool => ($row['id'] ?? null) === $summary['id'])) {
            return;
        }

        $node['volumes'] = $volumes->push($summary)->values()->all();
        $nodes->put($index, $node);
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
        if ($kind === 'agent') {
            return ['icon' => null, 'glyph' => 'agent'];
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
     * Left-to-right flow: ingress → apps → data → agent / orphans.
     * Child domains/volumes cluster around their parent so new services stack
     * in lanes instead of sprawling into spaghetti.
     *
     * @param  Collection<int, array<string, mixed>>  $nodes
     * @return array<string, array{x: float, y: float}>
     */
    private static function defaultPositions(Collection $nodes): array
    {
        $laneFor = static function (string $kind): int {
            return match ($kind) {
                'github' => 0,
                'odoo', 'application', 'jupyter', 'monitor', 'service' => 1,
                'database' => 2,
                'agent' => 3,
                default => 1,
            };
        };

        $counters = [];
        $out = [];
        $volumeChildren = [];
        $domainChildren = [];
        $orphanVolumes = [];

        foreach ($nodes as $node) {
            $kind = (string) ($node['kind'] ?? 'service');
            $parentId = is_string($node['parent_id'] ?? null) ? $node['parent_id'] : null;
            if ($kind === 'volume') {
                if ($parentId !== null) {
                    $volumeChildren[$parentId][] = (string) $node['id'];
                } else {
                    $orphanVolumes[] = (string) $node['id'];
                }

                continue;
            }
            if ($kind === 'domain') {
                if ($parentId !== null) {
                    $domainChildren[$parentId][] = (string) $node['id'];
                }

                continue;
            }
            $lane = $laneFor($kind);
            $row = $counters[$lane] ?? 0;
            $out[(string) $node['id']] = [
                'x' => 96.0 + $lane * 340.0,
                'y' => 88.0 + $row * 190.0,
            ];
            $counters[$lane] = $row + 1;
        }

        foreach ($domainChildren as $parentId => $childIds) {
            $parent = $out[$parentId] ?? ['x' => 96.0 + 340.0, 'y' => 88.0];
            foreach (array_values($childIds) as $index => $childId) {
                $out[$childId] = [
                    'x' => $parent['x'] - 240.0,
                    'y' => $parent['y'] + ($index * 64.0),
                ];
            }
        }

        foreach ($volumeChildren as $parentId => $childIds) {
            $parent = $out[$parentId] ?? ['x' => 96.0 + 340.0, 'y' => 88.0];
            foreach (array_values($childIds) as $index => $childId) {
                $out[$childId] = [
                    'x' => $parent['x'] + 36.0 + ($index % 2) * 180.0,
                    'y' => $parent['y'] + 126.0 + intdiv($index, 2) * 64.0,
                ];
            }
        }

        $orphanLane = 4;
        foreach (array_values($orphanVolumes) as $index => $childId) {
            $out[$childId] = [
                'x' => 96.0 + $orphanLane * 340.0,
                'y' => 88.0 + $index * 100.0,
            ];
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
