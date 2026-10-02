<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\AdminCreationQuotaExceeded;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\OdooStaging;
use Illuminate\Support\Facades\DB;

class AdminCreationQuota
{
    private static int $suspensionDepth = 0;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $additionalEnvironments
     */
    public function createProject(User $actor, array $attributes, array $additionalEnvironments = []): Project
    {
        $teamId = (int) $attributes['team_id'];

        return DB::transaction(function () use ($actor, $attributes, $additionalEnvironments, $teamId): Project {
            $this->assertWithinLimits(
                $this->lockedMembership($actor->id, $teamId),
                $actor->id,
                $teamId,
                projects: 1,
                environments: $this->extraEnvironmentCount($additionalEnvironments),
            );

            $project = Project::create([
                ...$attributes,
                'created_by' => $actor->id,
            ]);

            foreach ($additionalEnvironments as $environmentAttributes) {
                $project->environments()->create([
                    ...$environmentAttributes,
                    'created_by' => $actor->id,
                ]);
            }

            return $project;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createEnvironment(User $actor, Project $project, array $attributes): Environment
    {
        return DB::transaction(function () use ($actor, $project, $attributes): Environment {
            $name = (string) ($attributes['name'] ?? '');
            $this->assertWithinLimits(
                $this->lockedMembership($actor->id, $project->team_id),
                $actor->id,
                $project->team_id,
                environments: $this->environmentConsumesQuota($project->id, $name) ? 1 : 0,
            );

            return $project->environments()->create([
                ...$attributes,
                'created_by' => $actor->id,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createInvitation(User $actor, array $attributes): TeamInvitation
    {
        $teamId = (int) $attributes['team_id'];

        return DB::transaction(function () use ($actor, $attributes, $teamId): TeamInvitation {
            $this->assertWithinLimits(
                $this->lockedMembership($actor->id, $teamId),
                $actor->id,
                $teamId,
                members: 1,
            );

            return TeamInvitation::create([
                ...$attributes,
                'invited_by' => $actor->id,
            ]);
        });
    }

    /**
     * @return array{role: string, added_by?: int}
     */
    public function attributesForAcceptedInvitation(TeamInvitation $invitation): array
    {
        $attributes = ['role' => $invitation->role];

        if ($invitation->invited_by !== null) {
            $attributes['added_by'] = $invitation->invited_by;
        }

        return $attributes;
    }

    public function guardProject(Project $project): void
    {
        if ($this->suspended() || $project->team_id === null) {
            return;
        }

        $actor = auth()->user();
        if ($project->created_by === null && $actor !== null) {
            $project->created_by = $actor->id;
        }

        if (! $actor instanceof User || (int) $project->created_by !== (int) $actor->id) {
            return;
        }

        $teamId = (int) $project->team_id;
        $membership = DB::transactionLevel() > 0
            ? $this->lockedMembership($actor->id, $teamId)
            : $this->membership($actor->id, $teamId);

        $this->assertWithinLimits($membership, $actor->id, $teamId, projects: 1);
    }

    public function guardEnvironment(Environment $environment): void
    {
        if ($this->suspended() || $environment->project_id === null) {
            return;
        }

        $actor = auth()->user();
        if ($environment->created_by === null && $actor !== null) {
            $environment->created_by = $actor->id;
        }

        if (! $actor instanceof User || (int) $environment->created_by !== (int) $actor->id) {
            return;
        }

        $teamId = Project::query()->whereKey($environment->project_id)->value('team_id');
        if ($teamId === null) {
            return;
        }

        if (! $this->environmentConsumesQuota((int) $environment->project_id, (string) $environment->name)) {
            return;
        }

        $teamId = (int) $teamId;
        $membership = DB::transactionLevel() > 0
            ? $this->lockedMembership($actor->id, $teamId)
            : $this->membership($actor->id, $teamId);

        $this->assertWithinLimits($membership, $actor->id, $teamId, environments: 1);
    }

    public function guardApplication(Application $application): void
    {
        if ($this->suspended() || $application->environment_id === null) {
            return;
        }

        $actor = auth()->user();
        if ($application->created_by === null && $actor !== null) {
            $application->created_by = $actor->id;
        }

        if (! $actor instanceof User || (int) $application->created_by !== (int) $actor->id) {
            return;
        }

        $environment = Environment::query()->with('project:id,team_id')->find($application->environment_id);
        $teamId = $environment?->project?->team_id;
        $kind = $this->environmentKind($environment?->name);
        if ($teamId === null || $kind === null) {
            return;
        }

        $teamId = (int) $teamId;
        $membership = DB::transactionLevel() > 0
            ? $this->lockedMembership($actor->id, $teamId)
            : $this->membership($actor->id, $teamId);

        if ($kind === 'production') {
            $this->assertWithinLimits($membership, $actor->id, $teamId, productionBranches: 1);

            return;
        }

        $this->assertWithinLimits($membership, $actor->id, $teamId, stagingBranches: 1);
    }

    public function guardService(Service $service): void
    {
        if ($this->suspended() || $service->environment_id === null) {
            return;
        }

        $actor = auth()->user();
        if ($service->created_by === null && $actor !== null) {
            $service->created_by = $actor->id;
        }

        if (! $actor instanceof User || (int) $service->created_by !== (int) $actor->id) {
            return;
        }

        $teamId = Environment::query()->whereKey($service->environment_id)->with('project:id,team_id')->first()?->project?->team_id;
        if ($teamId === null) {
            return;
        }

        if ($this->includedOdooService($service)) {
            return;
        }

        $teamId = (int) $teamId;
        $membership = DB::transactionLevel() > 0
            ? $this->lockedMembership($actor->id, $teamId)
            : $this->membership($actor->id, $teamId);

        $this->assertWithinLimits($membership, $actor->id, $teamId, services: 1);
    }

    /**
     * Production and the first staging of a project are part of that one instance.
     * They do not spend an environment slot. Anything else does.
     *
     * @param  list<array<string, mixed>>  $additionalEnvironments
     */
    private function extraEnvironmentCount(array $additionalEnvironments): int
    {
        $stagingIncluded = false;
        $extra = 0;
        foreach ($additionalEnvironments as $environmentAttributes) {
            $name = (string) ($environmentAttributes['name'] ?? '');
            if (! $stagingIncluded && OdooStaging::isStagingName($name)) {
                $stagingIncluded = true;

                continue;
            }
            if (strcasecmp($name, 'production') === 0) {
                continue;
            }
            $extra++;
        }

        return $extra;
    }

    private function environmentConsumesQuota(int $projectId, string $name): bool
    {
        if (strcasecmp($name, 'production') === 0) {
            return false;
        }

        if (! OdooStaging::isStagingName($name)) {
            return true;
        }

        $already = Environment::query()
            ->where('project_id', $projectId)
            ->get(['name'])
            ->contains(fn (Environment $environment): bool => OdooStaging::isStagingName((string) $environment->name));

        return $already;
    }

    private function includedOdooService(Service $service): bool
    {
        if ($service->environment_id === null || ! $this->looksLikeOdoo($service)) {
            return false;
        }

        $environment = Environment::query()->find($service->environment_id);
        if (! $environment instanceof Environment) {
            return false;
        }

        if ($this->environmentConsumesQuota((int) $environment->project_id, (string) $environment->name)) {
            return false;
        }

        return ! Service::query()
            ->where('environment_id', $environment->id)
            ->when($service->exists, fn ($query) => $query->whereKeyNot($service->id))
            ->get()
            ->contains(fn (Service $row): bool => $this->looksLikeOdoo($row));
    }

    private function looksLikeOdoo(Service $service): bool
    {
        if ($service->service_type === 'odoo') {
            return true;
        }

        return $service->supportsOdooJupyter();
    }

    /**
     * Imports and other restores must not consume an admin's personal quota.
     */
    public function withoutEnforcement(callable $callback): mixed
    {
        self::$suspensionDepth++;

        try {
            return $callback();
        } finally {
            self::$suspensionDepth--;
        }
    }

    /**
     * @return array{projects: int, environments: int, members: int, production_branches: int, staging_branches: int, services: int}
     */
    public function usage(User $user, int $teamId): array
    {
        return [
            'projects' => $this->projectUsage($user->id, $teamId),
            'environments' => $this->environmentUsage($user->id, $teamId),
            'members' => $this->memberUsage($user->id, $teamId),
            'production_branches' => $this->branchUsage($user->id, $teamId, 'production'),
            'staging_branches' => $this->branchUsage($user->id, $teamId, 'staging'),
            'services' => $this->serviceUsage($user->id, $teamId),
        ];
    }

    /**
     * @return array{
     *     projects: array{used: int, limit: int|null},
     *     environments: array{used: int, limit: int|null},
     *     members: array{used: int, limit: int|null},
     *     production_branches: array{used: int, limit: int|null},
     *     staging_branches: array{used: int, limit: int|null},
     *     services: array{used: int, limit: int|null}
     * }|null
     */
    public function summaryForViewer(): ?array
    {
        $user = auth()->user();
        $team = currentTeam();

        if (! $user instanceof User || $team === null || $user->roleInTeam($team->id) !== Role::ADMIN->value) {
            return null;
        }

        $membership = $this->membership($user->id, $team->id);
        $usage = $this->usage($user, $team->id);

        return [
            'projects' => [
                'used' => $usage['projects'],
                'limit' => $this->limitValue($membership, 'max_projects'),
            ],
            'environments' => [
                'used' => $usage['environments'],
                'limit' => $this->limitValue($membership, 'max_environments'),
            ],
            'members' => [
                'used' => $usage['members'],
                'limit' => $this->limitValue($membership, 'max_members'),
            ],
            'production_branches' => [
                'used' => $usage['production_branches'],
                'limit' => $this->limitValue($membership, 'max_production_branches'),
            ],
            'staging_branches' => [
                'used' => $usage['staging_branches'],
                'limit' => $this->limitValue($membership, 'max_staging_branches'),
            ],
            'services' => [
                'used' => $usage['services'],
                'limit' => $this->limitValue($membership, 'max_services'),
            ],
        ];
    }

    private function suspended(): bool
    {
        return self::$suspensionDepth > 0;
    }

    private function membership(int $userId, int $teamId): ?object
    {
        return DB::table('team_user')
            ->where('team_id', $teamId)
            ->where('user_id', $userId)
            ->first();
    }

    private function lockedMembership(int $userId, int $teamId): ?object
    {
        $query = DB::table('team_user')
            ->where('team_id', $teamId)
            ->where('user_id', $userId);

        if (DB::getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function assertWithinLimits(
        ?object $membership,
        int $userId,
        int $teamId,
        int $projects = 0,
        int $environments = 0,
        int $members = 0,
        int $productionBranches = 0,
        int $stagingBranches = 0,
        int $services = 0,
    ): void {
        if ($membership === null || $membership->role === Role::OWNER->value) {
            return;
        }

        if ($projects > 0) {
            $this->assertLimit(
                $membership->max_projects,
                $this->projectUsage($userId, $teamId),
                $projects,
                'proyectos',
            );
        }

        if ($environments > 0) {
            $this->assertLimit(
                $membership->max_environments,
                $this->environmentUsage($userId, $teamId),
                $environments,
                'entornos',
            );
        }

        if ($members > 0) {
            $this->assertLimit(
                $membership->max_members,
                $this->memberUsage($userId, $teamId),
                $members,
                'miembros',
            );
        }

        if ($productionBranches > 0) {
            $this->assertLimit(
                $membership->max_production_branches,
                $this->branchUsage($userId, $teamId, 'production'),
                $productionBranches,
                'ramas de producción',
            );
        }

        if ($stagingBranches > 0) {
            $this->assertLimit(
                $membership->max_staging_branches,
                $this->branchUsage($userId, $teamId, 'staging'),
                $stagingBranches,
                'ramas de staging',
            );
        }

        if ($services > 0) {
            $this->assertLimit(
                $membership->max_services,
                $this->serviceUsage($userId, $teamId),
                $services,
                'servicios',
            );
        }
    }

    private function assertLimit(mixed $limit, int $used, int $needed, string $label): void
    {
        if ($limit === null || $needed === 0) {
            return;
        }

        $limit = (int) $limit;

        if ($used + $needed > $limit) {
            throw new AdminCreationQuotaExceeded($label, $used, $limit);
        }
    }

    private function projectUsage(int $userId, int $teamId): int
    {
        return Project::query()
            ->where('team_id', $teamId)
            ->where('created_by', $userId)
            ->count();
    }

    private function environmentUsage(int $userId, int $teamId): int
    {
        return Environment::query()
            ->where('created_by', $userId)
            ->whereHas('project', fn ($query) => $query->where('team_id', $teamId))
            ->count();
    }

    private function environmentKind(?string $name): ?string
    {
        $normalized = mb_strtolower(trim((string) $name));

        if (in_array($normalized, $this->branchNames('production'), true)) {
            return 'production';
        }

        if (in_array($normalized, $this->branchNames('staging'), true)) {
            return 'staging';
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function branchNames(string $kind): array
    {
        return $kind === 'production'
            ? ['production', 'produccion', 'producción', 'prod']
            : ['staging', 'stage', 'stg'];
    }

    private function branchUsage(int $userId, int $teamId, string $kind): int
    {
        $names = $this->branchNames($kind);

        return Application::query()
            ->where('created_by', $userId)
            ->whereHas('environment', function ($query) use ($teamId, $names) {
                $query->whereHas('project', fn ($project) => $project->where('team_id', $teamId))
                    ->where(function ($nameQuery) use ($names) {
                        foreach ($names as $name) {
                            $nameQuery->orWhereRaw('lower(name) = ?', [$name]);
                        }
                    });
            })
            ->count();
    }

    private function serviceUsage(int $userId, int $teamId): int
    {
        return Service::query()
            ->where('created_by', $userId)
            ->whereHas('environment.project', fn ($query) => $query->where('team_id', $teamId))
            ->count();
    }

    private function memberUsage(int $userId, int $teamId): int
    {
        $accepted = DB::table('team_user')
            ->where('team_id', $teamId)
            ->where('added_by', $userId)
            ->count();

        $memberEmails = DB::table('users')
            ->join('team_user', 'team_user.user_id', '=', 'users.id')
            ->where('team_user.team_id', $teamId)
            ->pluck('users.email')
            ->map(fn ($email): string => strtolower((string) $email));

        $pending = TeamInvitation::query()
            ->where('team_id', $teamId)
            ->where('invited_by', $userId)
            ->get()
            ->reject(fn (TeamInvitation $invitation): bool => $invitation->hasExpired()
                || $memberEmails->contains(strtolower($invitation->email)))
            ->count();

        return $accepted + $pending;
    }

    /**
     * Owners are not capped. An empty admin limit means unlimited. Members cannot launch staging.
     */
    public function canLaunchStaging(User $user, int $teamId, ?Project $project = null): bool
    {
        if ($user->isInstanceOwner() || $user->isOwnerOfTeam($teamId)) {
            return true;
        }

        $membership = $this->membership($user->id, $teamId);
        if ($membership === null || $membership->role === Role::MEMBER->value) {
            return false;
        }

        if ($project instanceof Project && ! $this->projectHasStaging($project)) {
            return true;
        }

        if ($membership->max_staging_branches === null) {
            return true;
        }

        return $this->stagingLaunchUsage($user->id, $teamId) < (int) $membership->max_staging_branches;
    }

    private function projectHasStaging(Project $project): bool
    {
        return $project->environments()
            ->get(['name'])
            ->contains(fn (Environment $environment): bool => OdooStaging::isStagingName((string) $environment->name));
    }

    public function stagingLaunchUsage(int $userId, int $teamId): int
    {
        return Environment::query()
            ->where('created_by', $userId)
            ->whereHas('project', fn ($query) => $query->where('team_id', $teamId))
            ->get(['id', 'name'])
            ->filter(fn (Environment $environment): bool => OdooStaging::isStagingName($environment->name)
                || in_array(mb_strtolower(trim((string) $environment->name)), ['stage', 'stg'], true))
            ->count();
    }

    private function limitValue(?object $membership, string $column): ?int
    {
        if ($membership === null || $membership->{$column} === null) {
            return null;
        }

        return (int) $membership->{$column};
    }
}
