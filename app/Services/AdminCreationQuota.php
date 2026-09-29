<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\AdminCreationQuotaExceeded;
use App\Models\Environment;
use App\Models\Project;
use App\Models\TeamInvitation;
use App\Models\User;
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
                environments: 1 + count($additionalEnvironments),
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
            $this->assertWithinLimits(
                $this->lockedMembership($actor->id, $project->team_id),
                $actor->id,
                $project->team_id,
                environments: 1,
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

        $this->assertWithinLimits($membership, $actor->id, $teamId, projects: 1, environments: 1);
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

        $teamId = (int) $teamId;
        $membership = DB::transactionLevel() > 0
            ? $this->lockedMembership($actor->id, $teamId)
            : $this->membership($actor->id, $teamId);

        $this->assertWithinLimits($membership, $actor->id, $teamId, environments: 1);
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
     * @return array{projects: int, environments: int, members: int}
     */
    public function usage(User $user, int $teamId): array
    {
        return [
            'projects' => $this->projectUsage($user->id, $teamId),
            'environments' => $this->environmentUsage($user->id, $teamId),
            'members' => $this->memberUsage($user->id, $teamId),
        ];
    }

    /**
     * @return array{
     *     projects: array{used: int, limit: int|null},
     *     environments: array{used: int, limit: int|null},
     *     members: array{used: int, limit: int|null}
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
    ): void {
        if ($membership === null || $membership->role !== Role::ADMIN->value) {
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

    private function memberUsage(int $userId, int $teamId): int
    {
        $accepted = DB::table('team_user')
            ->where('team_id', $teamId)
            ->where('added_by', $userId)
            ->count();

        $pending = TeamInvitation::query()
            ->where('team_id', $teamId)
            ->where('invited_by', $userId)
            ->get()
            ->reject(fn (TeamInvitation $invitation): bool => $invitation->hasExpired())
            ->count();

        return $accepted + $pending;
    }

    private function limitValue(?object $membership, string $column): ?int
    {
        if ($membership === null || $membership->{$column} === null) {
            return null;
        }

        return (int) $membership->{$column};
    }
};
