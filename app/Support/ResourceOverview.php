<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Client-facing summary of a team's applications and services: a readable name,
 * a simple state (running / stopped / starting / attention) and where to open it.
 * Used by the dashboard and the project page.
 */
class ResourceOverview
{
    public const RUNNING = 'running';

    public const STOPPED = 'stopped';

    public const STARTING = 'starting';

    public const ATTENTION = 'attention';

    /**
     * Eager loads what {@see rows()} needs so a list of projects costs a fixed number of queries.
     *
     * @return array<string|int, mixed>
     */
    public static function eagerLoads(): array
    {
        return [
            'environments:id,uuid,name,project_id,role',
            'environments.applications',
            'environments.services.applications',
            'environments.services.databases',
        ];
    }

    /**
     * @return Collection<int, array{kind: string, uuid: string, name: string, raw_name: string, state: string, url: string, domain: ?string, project: string, environment: string, updated_at: mixed}>
     */
    public static function rows(Project $project): Collection
    {
        return $project->environments->flatMap(function (Environment $environment) use ($project) {
            $applications = $environment->applications->map(fn (Application $application): array => [
                'kind' => 'application',
                'uuid' => $application->uuid,
                'name' => self::readableName((string) $application->name, (string) $application->uuid),
                'raw_name' => (string) $application->name,
                'state' => self::state((string) $application->status),
                'url' => route('project.application.configuration', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $environment->uuid,
                    'application_uuid' => $application->uuid,
                ]),
                'domain' => self::firstDomain($application->fqdn),
                'project' => (string) $project->name,
                'environment' => (string) $environment->name,
                'updated_at' => $application->updated_at,
            ]);

            $services = $environment->services->map(fn (Service $service): array => [
                'kind' => 'service',
                'uuid' => $service->uuid,
                'name' => self::readableName((string) $service->name, (string) $service->uuid),
                'raw_name' => (string) $service->name,
                'state' => self::state((string) $service->status),
                'url' => route('project.service.configuration', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $environment->uuid,
                    'service_uuid' => $service->uuid,
                ]),
                'domain' => self::firstDomain($service->applications->pluck('fqdn')->filter()->first()),
                'project' => (string) $project->name,
                'environment' => (string) $environment->name,
                'updated_at' => $service->updated_at,
            ]);

            return $applications->concat($services);
        })->values();
    }

    /**
     * Services are created as "<template>-<uuid>"; people should read "Authentik", not the uuid.
     */
    public static function readableName(string $name, string $uuid): string
    {
        $name = trim($name);
        if ($uuid !== '' && Str::endsWith($name, '-'.$uuid)) {
            $base = Str::beforeLast($name, '-'.$uuid);

            return $base === '' ? $name : Str::ucfirst(str_replace(['-', '_'], ' ', $base));
        }

        return $name;
    }

    public static function state(string $status): string
    {
        $status = strtolower($status);

        return match (true) {
            str_starts_with($status, 'running') && str_contains($status, 'unhealthy') => self::ATTENTION,
            str_starts_with($status, 'running') => self::RUNNING,
            str_starts_with($status, 'starting'), str_starts_with($status, 'restarting') => self::STARTING,
            str_starts_with($status, 'degraded') => self::ATTENTION,
            default => self::STOPPED,
        };
    }

    private static function firstDomain(?string $fqdn): ?string
    {
        $first = trim((string) Str::of((string) $fqdn)->explode(',')->first());
        if ($first === '') {
            return null;
        }

        return (string) (parse_url($first, PHP_URL_HOST) ?: $first);
    }
}
