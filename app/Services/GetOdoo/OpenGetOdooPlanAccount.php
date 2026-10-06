<?php

namespace App\Services\GetOdoo;

use App\Models\GetOdooPlan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class OpenGetOdooPlanAccount
{
    public function open(string $name, string $email, string $password, GetOdooPlan $plan): User
    {
        if (User::query()->count() === 0) {
            throw new RuntimeException('The root account does not exist yet.');
        }

        $email = strtolower($email);
        $existing = User::query()->where('email', $email)->first();

        if ($existing instanceof User) {
            $this->attachPlan($existing, $plan);

            return $existing;
        }

        $user = User::withoutPersonalTeam(function () use ($name, $email, $password) {
            return User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);
        });

        if (isCloud()) {
            $user->sendVerificationEmail();
        } else {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $this->attachPlan($user, $plan);

        return $user;
    }

    private function attachPlan(User $user, GetOdooPlan $plan): void
    {
        $team = $user->teams()->where('getodoo_plan_id', $plan->id)->first();

        if (! $team instanceof Team) {
            $team = new Team;
            $team->forceFill([
                'name' => $user->name,
                'personal_team' => true,
                'show_boarding' => true,
                'getodoo_plan_id' => $plan->id,
            ]);
            $team->save();
            $user->teams()->attach($team->id, $this->membership($plan));

            return;
        }

        if ((int) $team->id === 0) {
            return;
        }

        $user->teams()->updateExistingPivot($team->id, $this->membership($plan));
    }

    /**
     * @return array{role: string, max_projects: ?int, max_environments: ?int, max_members: ?int, max_production_branches: ?int, max_staging_branches: ?int, max_services: ?int, can_add_servers: bool, can_launch_on_instance_server: bool}
     */
    private function membership(GetOdooPlan $plan): array
    {
        return [
            'role' => 'admin',
            'max_projects' => $plan->max_projects,
            'max_environments' => $plan->max_environments,
            'max_members' => $plan->max_members,
            'max_production_branches' => $plan->max_production_branches,
            'max_staging_branches' => $plan->max_staging_branches,
            'max_services' => $plan->max_services,
            'can_add_servers' => $plan->can_add_servers,
            'can_launch_on_instance_server' => $plan->can_launch_on_instance_server,
        ];
    }
}
