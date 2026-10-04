<?php

namespace App\Domain\Odoo;

use App\Models\OdooEnvironmentBranch;
use App\Models\Project;

class OdooDomains
{
    public static function normalizedBaseDomain(string $domain): string
    {
        $base = strtolower(trim($domain));
        $base = preg_replace('#^https?://#', '', $base) ?? '';
        $base = explode('/', $base)[0];
        $base = explode(':', $base)[0];

        return preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/', $base) === 1
            ? $base
            : '';
    }

    public static function projectHost(string $subdomain, string $baseDomain, ?string $environment = null, int $environmentId = 0): string
    {
        $base = self::normalizedBaseDomain($baseDomain);
        $label = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($subdomain)), '-');
        if ($base === '' || $label === '') {
            return '';
        }
        $suffix = '';
        if ($environment !== null && strcasecmp($environment, 'production') !== 0) {
            $branch = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($environment)), '-');
            $suffix = '-'.($branch !== '' ? $branch : 'staging').'-'.$environmentId;
        }
        $label = trim(substr($label, 0, max(1, 63 - strlen($suffix))), '-').$suffix;
        $label = trim(substr($label, 0, 63), '-');

        return $label === '' ? '' : $label.'.'.$base;
    }

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

        $used = OdooEnvironmentBranch::query()
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
