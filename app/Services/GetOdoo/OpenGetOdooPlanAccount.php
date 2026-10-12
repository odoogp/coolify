<?php

namespace App\Services\GetOdoo;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPricingArea;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class OpenGetOdooPlanAccount
{
    public function open(
        string $name,
        string $email,
        string $password,
        GetOdooPlan $plan,
        ?GetOdooPricingArea $pricingArea = null,
        bool $acceptedTerms = false,
        ?string $organization = null,
    ): User {
        if (User::query()->count() === 0) {
            throw new RuntimeException('The root account does not exist yet.');
        }

        $email = strtolower($email);
        $organization = trim((string) $organization);
        if ($organization === '') {
            $organization = trim($name);
        }

        return DB::transaction(function () use ($name, $email, $password, $plan, $pricingArea, $acceptedTerms, $organization) {
            $existing = User::query()->where('email', $email)->first();

            if ($existing instanceof User) {
                if ($acceptedTerms) {
                    $existing->acceptTerms();
                }
                $this->attachPlan($existing, $plan, $pricingArea, $organization);

                return $existing;
            }

            $user = User::withoutPersonalTeam(function () use ($name, $email, $password, $pricingArea, $acceptedTerms) {
                return User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make($password),
                    'country_iso' => self::isoFromArea($pricingArea),
                    'terms_accepted_at' => $acceptedTerms ? now() : null,
                ]);
            });

            if (isCloud()) {
                $user->sendVerificationEmail();
            } else {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $this->attachPlan($user, $plan, $pricingArea, $organization);

            return $user;
        });
    }

    private function attachPlan(
        User $user,
        GetOdooPlan $plan,
        ?GetOdooPricingArea $pricingArea = null,
        ?string $organization = null,
    ): void {
        $iso = self::isoFromArea($pricingArea);
        if ($iso !== null && blank($user->country_iso)) {
            $user->forceFill(['country_iso' => $iso])->save();
        }

        $team = $user->teams()->where('getodoo_plan_id', $plan->id)->first();
        $teamName = filled($organization) ? trim($organization) : $user->name;

        if (! $team instanceof Team) {
            $team = new Team;
            $team->forceFill([
                'name' => $teamName,
                'personal_team' => false,
                'show_boarding' => true,
                'getodoo_plan_id' => $plan->id,
                'getodoo_pricing_area_id' => $pricingArea?->id,
            ]);
            $team->save();
            // Owner of their organization; server flags still come from the plan pivot.
            $user->teams()->attach($team->id, $this->membership($plan, $pricingArea));

            return;
        }

        if ((int) $team->id === 0) {
            return;
        }

        $team->forceFill([
            'name' => filled($organization) ? $teamName : $team->name,
            'personal_team' => false,
            'getodoo_pricing_area_id' => $pricingArea?->id ?? $team->getodoo_pricing_area_id,
        ])->save();

        $user->teams()->updateExistingPivot($team->id, $this->membership($plan, $pricingArea));
    }

    private static function isoFromArea(?GetOdooPricingArea $pricingArea): ?string
    {
        if (! $pricingArea instanceof GetOdooPricingArea || ! $pricingArea->isCountry()) {
            return null;
        }

        $iso = strtoupper((string) ($pricingArea->iso_code ?: $pricingArea->code));

        return strlen($iso) === 2 ? $iso : null;
    }

    /**
     * @return array{role: string, max_projects: ?int, max_environments: ?int, max_members: ?int, max_production_branches: ?int, max_staging_branches: ?int, max_services: ?int, can_add_servers: bool, can_launch_on_instance_server: bool}
     */
    private function membership(GetOdooPlan $plan, ?GetOdooPricingArea $pricingArea = null): array
    {
        $entitlements = GetOdooAreaEntitlements::resolve($pricingArea);

        return [
            'role' => 'owner',
            'max_projects' => GetOdooAreaEntitlements::capMaxProjects(
                $plan->max_projects !== null ? (int) $plan->max_projects : null,
                $entitlements,
            ),
            'max_environments' => $plan->max_environments,
            'max_members' => $plan->max_members,
            'max_production_branches' => $plan->max_production_branches,
            'max_staging_branches' => $plan->max_staging_branches,
            'max_services' => $plan->max_services,
            'can_add_servers' => (bool) $plan->can_add_servers,
            'can_launch_on_instance_server' => (bool) $plan->can_launch_on_instance_server,
        ];
    }
}
