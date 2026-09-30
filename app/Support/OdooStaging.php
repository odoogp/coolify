<?php

namespace App\Support;

use App\Models\Environment;
use App\Models\Project;
use Illuminate\Support\Collection;

/**
 * Staging capacity for an Odoo project.
 *
 * A staging environment is a normal Coolify Environment whose name is
 * "staging" or "staging-N". Production is never counted. Git branches are not.
 */
class OdooStaging
{
    public static function isStagingName(string $name): bool
    {
        return preg_match('/^staging(?:-\d+)?$/i', $name) === 1;
    }

    /**
     * @return Collection<int, Environment>
     */
    public static function stagingEnvironments(Project $project): Collection
    {
        return $project->environments()
            ->get()
            ->filter(fn (Environment $environment): bool => self::isStagingName($environment->name))
            ->values();
    }

    public static function canCreateStagingEnvironment(Project $project): bool
    {
        $profile = $project->odooProfile;
        if ($profile === null) {
            return false;
        }

        if ($profile->unlimited_staging_environments) {
            return true;
        }

        return self::stagingEnvironments($project)->count() < (int) $profile->max_staging_environments;
    }

    public static function nextName(Project $project): string
    {
        $used = [];
        foreach (self::stagingEnvironments($project) as $environment) {
            if (strcasecmp($environment->name, 'staging') === 0) {
                $used[1] = true;
            }
            if (preg_match('/^staging-(\d+)$/i', $environment->name, $matches) === 1) {
                $used[(int) $matches[1]] = true;
            }
        }

        $number = 1;
        while (isset($used[$number])) {
            $number++;
        }

        return 'staging-'.$number;
    }
}
