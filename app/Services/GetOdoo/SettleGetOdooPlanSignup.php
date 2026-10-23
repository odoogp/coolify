<?php

namespace App\Services\GetOdoo;

use App\Models\GetOdooPlanSignup;
use App\Models\Team;
use App\Models\User;
use App\Support\GetOdooPlanBilling;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SettleGetOdooPlanSignup
{
    public function __construct(private OpenGetOdooPlanAccount $accounts) {}

    public function settle(GetOdooPlanSignup $signup, string $transactionId): User
    {
        return DB::transaction(function () use ($signup, $transactionId) {
            $signup = GetOdooPlanSignup::query()->whereKey($signup->id)->lockForUpdate()->firstOrFail();
            $signup->loadMissing(['plan', 'pricingArea']);

            if ($signup->status === 'paid') {
                $signup->load('user');

                if ($signup->user instanceof User) {
                    $this->stampTeamBilling($signup, $signup->user);

                    return $signup->user;
                }
            }

            if ($signup->status !== 'awaiting_payment' || $signup->plan === null || ! is_string($signup->password) || $signup->password === '') {
                throw new RuntimeException('This signup cannot be paid.');
            }

            $user = $this->accounts->open(
                $signup->name,
                $signup->email,
                $signup->password,
                $signup->plan,
                $signup->pricingArea,
                acceptedTerms: true,
                organization: $signup->organization,
            );

            $signup->update([
                'status' => 'paid',
                'user_id' => $user->id,
                'wompi_transaction_id' => $transactionId,
                'password' => null,
                'wompi_link_url' => null,
            ]);

            $this->stampTeamBilling($signup->fresh(), $user);

            return $user;
        });
    }

    private function stampTeamBilling(GetOdooPlanSignup $signup, User $user): void
    {
        $plan = $signup->plan;
        if ($plan === null) {
            return;
        }

        $team = $user->teams()->where('getodoo_plan_id', $plan->id)->first();
        if (! $team instanceof Team) {
            return;
        }

        if ($team->getodoo_plan_paid_until === null) {
            $anchor = now()->startOfDay();
            $next = $anchor->copy()->startOfMonth()->addMonthsNoOverflow(1);
            $next = $next->day(min($anchor->day, $next->daysInMonth))->startOfDay();
            $team->forceFill([
                'getodoo_plan_billing_anchor' => $anchor->toDateString(),
                'getodoo_plan_paid_until' => $next->toDateString(),
            ])->save();
        }

        GetOdooPlanBilling::stampPreference($team, $plan, forceInitial: true);
        GetOdooPlanBilling::syncSubscriptionFromSignup($team, $signup);
    }
}
