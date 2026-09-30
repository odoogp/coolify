<?php

namespace App\Livewire\Team;

use App\Actions\User\RevokeUserTeamTokens;
use App\Enums\Role;
use App\Models\User;
use App\Services\AdminCreationQuota;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Member extends Component
{
    use AuthorizesRequests;

    public User $member;

    public mixed $maxProjects = null;

    public mixed $maxEnvironments = null;

    public mixed $maxMembers = null;

    public mixed $maxProductionBranches = null;

    public mixed $maxStagingBranches = null;

    public mixed $maxServices = null;

    public function mount(): void
    {
        $team = currentTeam();
        if ($team === null) {
            return;
        }

        $pivot = $this->member->teams()->where('teams.id', $team->id)->first()?->pivot;
        $this->maxProjects = $this->quotaInput(data_get($pivot, 'max_projects'));
        $this->maxEnvironments = $this->quotaInput(data_get($pivot, 'max_environments'));
        $this->maxMembers = $this->quotaInput(data_get($pivot, 'max_members'));
        $this->maxProductionBranches = $this->quotaInput(data_get($pivot, 'max_production_branches'));
        $this->maxStagingBranches = $this->quotaInput(data_get($pivot, 'max_staging_branches'));
        $this->maxServices = $this->quotaInput(data_get($pivot, 'max_services'));
    }

    public function saveCreationLimits(): void
    {
        try {
            $team = currentTeam();
            $this->authorize('updateCreationLimits', $team);

            if ($this->getMemberRole() !== Role::ADMIN->value) {
                throw new \Exception('Creation limits apply to admins.');
            }

            $this->maxProjects = $this->blankToNull($this->maxProjects);
            $this->maxEnvironments = $this->blankToNull($this->maxEnvironments);
            $this->maxMembers = $this->blankToNull($this->maxMembers);
            $this->maxProductionBranches = $this->blankToNull($this->maxProductionBranches);
            $this->maxStagingBranches = $this->blankToNull($this->maxStagingBranches);
            $this->maxServices = $this->blankToNull($this->maxServices);

            $this->validate([
                'maxProjects' => ['nullable', 'integer', 'min:0'],
                'maxEnvironments' => ['nullable', 'integer', 'min:0'],
                'maxMembers' => ['nullable', 'integer', 'min:0'],
                'maxProductionBranches' => ['nullable', 'integer', 'min:0'],
                'maxStagingBranches' => ['nullable', 'integer', 'min:0'],
                'maxServices' => ['nullable', 'integer', 'min:0'],
            ]);

            $teamId = $team->id;
            $this->member->teams()->updateExistingPivot($teamId, [
                'max_projects' => $this->maxProjects,
                'max_environments' => $this->maxEnvironments,
                'max_members' => $this->maxMembers,
                'max_production_branches' => $this->maxProductionBranches,
                'max_staging_branches' => $this->maxStagingBranches,
                'max_services' => $this->maxServices,
            ]);
            Cache::forget('user:'.$this->member->id.':team:'.$teamId);
            Cache::forget('team:'.$this->member->id);
            $this->dispatch('success', __('Creation limits saved.'));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function makeAdmin()
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            if (Role::from(auth()->user()->role())->lt(Role::ADMIN)
                || Role::from($this->getMemberRole())->gt(auth()->user()->role())) {
                throw new \Exception('You are not authorized to perform this action.');
            }
            $teamId = currentTeam()->id;
            DB::transaction(function () use ($teamId): void {
                $this->member->teams()->updateExistingPivot($teamId, ['role' => Role::ADMIN->value]);
                RevokeUserTeamTokens::forUserTeam($this->member, $teamId);
            });
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function makeOwner()
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            if (Role::from(auth()->user()->role())->lt(Role::OWNER)
                || Role::from($this->getMemberRole())->gt(auth()->user()->role())) {
                throw new \Exception('You are not authorized to perform this action.');
            }
            $teamId = currentTeam()->id;
            DB::transaction(function () use ($teamId): void {
                $this->member->teams()->updateExistingPivot($teamId, ['role' => Role::OWNER->value]);
                RevokeUserTeamTokens::forUserTeam($this->member, $teamId);
            });
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function makeReadonly()
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            if (Role::from(auth()->user()->role())->lt(Role::ADMIN)
                || Role::from($this->getMemberRole())->gt(auth()->user()->role())) {
                throw new \Exception('You are not authorized to perform this action.');
            }
            $teamId = currentTeam()->id;
            DB::transaction(function () use ($teamId): void {
                $this->member->teams()->updateExistingPivot($teamId, ['role' => Role::MEMBER->value]);
                RevokeUserTeamTokens::forUserTeam($this->member, $teamId);
            });
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function remove()
    {
        try {
            $this->authorize('manageMembers', currentTeam());

            if (Role::from(auth()->user()->role())->lt(Role::ADMIN)
                || Role::from($this->getMemberRole())->gt(auth()->user()->role())) {
                throw new \Exception('You are not authorized to perform this action.');
            }
            $teamId = currentTeam()->id;
            DB::transaction(function () use ($teamId): void {
                $this->member->teams()->detach($teamId);
                RevokeUserTeamTokens::forUserTeam($this->member, $teamId);
            });
            // Clear cache for the removed user - both old and new key formats
            Cache::forget("team:{$this->member->id}");
            Cache::forget("user:{$this->member->id}:team:{$teamId}");
            $this->dispatch('reloadWindow');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());
        }
    }

    public function render()
    {
        $team = currentTeam();
        $canEditLimits = $team !== null
            && auth()->user()?->can('updateCreationLimits', $team)
            && $this->getMemberRole() === Role::ADMIN->value;

        return view('livewire.team.member', [
            'canEditLimits' => $canEditLimits,
            'usage' => $canEditLimits
                ? app(AdminCreationQuota::class)->usage($this->member, $team->id)
                : null,
        ]);
    }

    private function getMemberRole()
    {
        return $this->member->teams()->where('teams.id', currentTeam()->id)->first()?->pivot?->role;
    }

    private function quotaInput(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function blankToNull(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value;
    }
}
