<?php

namespace App\Domain\Odoo;

use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use App\Services\AdminCreationQuota;
use Illuminate\Support\Collection;

/**
 * Default staging names and launch quota for any project.
 *
 * Identity is {@see Environment::$role} (production|staging). Display names
 * stay free (`main`, `staging-2`, …). `nextName` only suggests unused
 * `staging-N` labels when creating a new staging environment.
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
        return $project->stagingEnvironments();
    }

    public static function canCreateStagingEnvironment(Project $project): bool
    {
        if ($project->team_id === null) {
            return false;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        return app(AdminCreationQuota::class)->canLaunchStaging($user, (int) $project->team_id, $project);
    }

    public static function nextName(Project $project): string
    {
        $used = [];
        foreach ($project->environments()->get(['name']) as $environment) {
            if (strcasecmp((string) $environment->name, 'staging') === 0) {
                $used[1] = true;
            }
            if (preg_match('/^staging-(\d+)$/i', (string) $environment->name, $matches) === 1) {
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
