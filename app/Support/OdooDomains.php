<?php

namespace App\Support;

use App\Models\Project;

class OdooDomains
{
    public static function host(string $domain): string
    {
        $domain = trim($domain);
        if (! str_contains($domain, '://')) {
            $domain = 'https://'.$domain;
        }

        $host = parse_url($domain, PHP_URL_HOST);

        return strtolower(rtrim((string) $host, '.'));
    }

    public static function collides(Project $project, string $domain, ?int $exceptEnvironmentId = null): bool
    {
        $host = self::host($domain);
        if ($host === '') {
            return true;
        }

        $environmentIds = $project->environments()->pluck('id');

        $used = \App\Models\OdooEnvironmentBranch::query()
            ->whereIn('environment_id', $environmentIds)
            ->when($exceptEnvironmentId !== null, fn ($query) => $query->where('environment_id', '!=', $exceptEnvironmentId))
            ->whereNotNull('domain')
            ->pluck('domain');

        foreach ($used as $existing) {
            if (self::host((string) $existing) === $host) {
                return true;
            }
        }

        return false;
    }
}
