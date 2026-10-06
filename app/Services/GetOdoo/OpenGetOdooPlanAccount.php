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

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);

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
        $team = $user->teams()->first();

        if (! $team instanceof Team || (int) $team->id === 0 || $team->getodoo_plan_id !== null) {
            return;
        }

        $team->forceFill(['getodoo_plan_id' => $plan->id])->save();
    }
}
