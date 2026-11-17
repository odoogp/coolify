<?php

namespace App\Support;

use App\Models\Environment;
use App\Models\EnvironmentCanvasLayout;
use App\Models\EnvironmentStackTemplate;
use App\Models\OdooProfile;
use App\Models\Service;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class EnvironmentStackTemplateCatalog
{
    /**
     * Snapshot the live environment into a team-scoped multi-service template.
     * Stock catalog types are referenced by name — compose is never inlined.
     */
    public static function saveFromEnvironment(
        Environment $environment,
        string $displayName,
        ?string $description,
        int $teamId,
        ?int $userId,
    ): EnvironmentStackTemplate {
        $displayName = trim($displayName);
        if ($displayName === '') {
            throw new \InvalidArgumentException('Give the stack template a name.');
        }

        $environment->loadMissing([
            'services.applications',
            'canvasLayout',
            'project.odooProfile',
        ]);

        $services = $environment->services->values();
        if ($services->isEmpty()) {
            throw new \InvalidArgumentException('This environment has no services to save.');
        }

        [$serviceRows, $uuidToKey] = self::snapshotServices($services, $environment->project?->odooProfile);
        $connections = self::snapshotConnections($environment->canvasLayout, $uuidToKey, $services);

        $name = self::uniqueSlug($teamId, $displayName);

        return EnvironmentStackTemplate::query()->create([
            'team_id' => $teamId,
            'created_by' => $userId,
            'name' => $name,
            'display_name' => $displayName,
            'description' => filled($description) ? trim($description) : null,
            'services' => $serviceRows,
            'connections' => $connections,
            'positions' => null,
        ]);
    }

    /**
     * @return Collection<int, EnvironmentStackTemplate>
     */
    public static function forTeam(int $teamId): Collection
    {
        return EnvironmentStackTemplate::query()
            ->where('team_id', $teamId)
            ->orderBy('display_name')
            ->get();
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return array{0: list<array<string, mixed>>, 1: array<string, string>}
     */
    private static function snapshotServices(Collection $services, ?OdooProfile $profile): array
    {
        $used = [];
        $rows = [];
        $uuidToKey = [];

        foreach ($services as $service) {
            $type = trim((string) ($service->service_type ?: ''));
            if ($type === '') {
                $type = $service->supportsOdooJupyter() ? 'odoo' : 'custom';
            }
            $base = Str::slug($type) ?: 'service';
            $key = $base;
            $n = 2;
            while (isset($used[$key])) {
                $key = $base.'-'.$n;
                $n++;
            }
            $used[$key] = true;
            $uuidToKey[(string) $service->uuid] = $key;

            $row = [
                'key' => $key,
                'service_type' => $type,
                'connect_to_docker_network' => (bool) $service->connect_to_docker_network,
            ];
            if ($service->supportsOdooJupyter()) {
                $row['odoo_version'] = (string) ($profile?->odoo_version ?: '20');
            }
            $rows[] = $row;
        }

        return [$rows, $uuidToKey];
    }

    /**
     * Remap live node ids → stable service keys for intranet edges only.
     *
     * @param  array<string, string>  $uuidToKey
     * @param  Collection<int, Service>  $services
     * @return list<array{id: string, from_key: string, to_key: string, label: string}>
     */
    private static function snapshotConnections(
        ?EnvironmentCanvasLayout $layout,
        array $uuidToKey,
        Collection $services,
    ): array {
        if (! $layout instanceof EnvironmentCanvasLayout) {
            return [];
        }

        $nodeToKey = [];
        foreach ($services as $service) {
            $key = $uuidToKey[(string) $service->uuid] ?? null;
            if ($key === null) {
                continue;
            }
            foreach ($service->applications as $application) {
                $nodeToKey['stack-app:'.$application->uuid] = $key;
            }
            $nodeToKey['github:'.$service->uuid] = $key;
            foreach ($service->databases as $database) {
                $nodeToKey['stack-db:'.$database->uuid] = $key;
            }
        }

        $out = [];
        foreach ($layout->connectionsList() as $edge) {
            $fromKey = $nodeToKey[$edge['from']] ?? null;
            $toKey = $nodeToKey[$edge['to']] ?? null;
            if ($fromKey === null || $toKey === null || $fromKey === $toKey) {
                continue;
            }
            $label = (string) ($edge['label'] ?? 'intranet');
            if ($label === 'git' || $label === 'context') {
                continue;
            }
            $out[] = [
                'id' => 'link:'.$fromKey.'->'.$toKey,
                'from_key' => $fromKey,
                'to_key' => $toKey,
                'label' => $label === '' ? 'intranet' : $label,
            ];
        }

        return array_values(collect($out)->unique(fn (array $row): string => $row['from_key'].'|'.$row['to_key'])->all());
    }

    private static function uniqueSlug(int $teamId, string $displayName): string
    {
        $base = Str::slug($displayName) ?: 'stack';
        $base = substr($base, 0, 80);
        $name = $base;
        $n = 2;
        while (EnvironmentStackTemplate::query()->where('team_id', $teamId)->where('name', $name)->exists()) {
            $name = substr($base, 0, 70).'-'.$n;
            $n++;
        }

        return $name;
    }
}
