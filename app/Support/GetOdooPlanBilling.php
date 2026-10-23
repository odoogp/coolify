<?php

namespace App\Support;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanOrder;
use App\Models\GetOdooPlanSignup;
use App\Models\Team;

/**
 * Charge policy for GetOdoo plans (signup + renewals), with preference stored on the team.
 *
 * Plan charge_mode:
 * - once: every invoice is a one-time checkout
 * - subscription: every charge (including the first) uses n1co subscription
 * - hybrid: first signup is always once; later invoices use recurring only after opt-in
 */
class GetOdooPlanBilling
{
    public const MODE_ONCE = 'once';

    public const MODE_SUBSCRIPTION = 'subscription';

    public const MODE_HYBRID = 'hybrid';

    public const PREFERENCE_ONCE = 'once';

    public const PREFERENCE_RECURRING = 'recurring';

    /**
     * @return list<string>
     */
    public static function planModes(): array
    {
        return [self::MODE_ONCE, self::MODE_SUBSCRIPTION, self::MODE_HYBRID];
    }

    public static function planMode(GetOdooPlan $plan): string
    {
        $mode = (string) ($plan->charge_mode ?: self::MODE_ONCE);

        return in_array($mode, self::planModes(), true) ? $mode : self::MODE_ONCE;
    }

    public static function initialPreference(GetOdooPlan $plan): string
    {
        return self::planMode($plan) === self::MODE_SUBSCRIPTION
            ? self::PREFERENCE_RECURRING
            : self::PREFERENCE_ONCE;
    }

    /**
     * Charge mode for the first signup payment (never returns "hybrid").
     */
    public static function chargeModeForSignup(GetOdooPlan $plan): string
    {
        $mode = self::planMode($plan);

        if ($mode === self::MODE_SUBSCRIPTION) {
            return GetOdooPlan::CHARGE_SUBSCRIPTION;
        }

        // once + hybrid: first charge is always a one-time checkout.
        return GetOdooPlan::CHARGE_ONCE;
    }

    /**
     * Charge mode for a renewal order (never returns "hybrid").
     */
    public static function chargeModeForRenewal(Team $team, ?GetOdooPlan $plan = null): string
    {
        $plan ??= $team->getodooPlan;

        if ($plan instanceof GetOdooPlan && self::planMode($plan) === self::MODE_SUBSCRIPTION) {
            return GetOdooPlan::CHARGE_SUBSCRIPTION;
        }

        if ($team->prefersPlanRecurringBilling()) {
            $startsAt = $team->planRecurringBillingStartsAt();
            if ($startsAt !== null && $startsAt->copy()->startOfDay()->isFuture()) {
                return GetOdooPlan::CHARGE_ONCE;
            }

            return GetOdooPlan::CHARGE_SUBSCRIPTION;
        }

        return GetOdooPlan::CHARGE_ONCE;
    }

    public static function stampPreference(Team $team, GetOdooPlan $plan, bool $forceInitial = false): void
    {
        $current = (string) ($team->getodoo_plan_billing_preference ?: '');

        if (! $forceInitial && in_array($current, [self::PREFERENCE_ONCE, self::PREFERENCE_RECURRING], true)) {
            if ($team->prefersPlanRecurringBilling() && $team->getodoo_plan_recurring_starts_at === null && $team->getodoo_plan_paid_until !== null) {
                $team->forceFill([
                    'getodoo_plan_recurring_starts_at' => $team->getodoo_plan_paid_until->toDateString(),
                ])->save();
            }

            return;
        }

        $preference = self::initialPreference($plan);
        $startsAt = null;

        if ($preference === self::PREFERENCE_RECURRING) {
            $startsAt = $team->getodoo_plan_paid_until?->toDateString()
                ?? now()->startOfDay()->addMonth()->toDateString();
        }

        $team->forceFill([
            'getodoo_plan_billing_preference' => $preference,
            'getodoo_plan_recurring_starts_at' => $startsAt,
        ])->save();
    }

    public static function syncSubscriptionFromSignup(Team $team, GetOdooPlanSignup $signup): void
    {
        self::syncSubscriptionIds(
            $team,
            $signup->n1co_plan_id,
            $signup->n1co_subscription_id,
            $signup->n1co_subscription_status,
        );
    }

    public static function syncSubscriptionFromOrder(Team $team, GetOdooPlanOrder $order): void
    {
        self::syncSubscriptionIds(
            $team,
            $order->n1co_plan_id,
            $order->n1co_subscription_id,
            $order->n1co_subscription_status,
        );
    }

    public static function invalidatePendingRenewals(Team $team, ?string $expectedChargeMode = null): void
    {
        $query = GetOdooPlanOrder::query()
            ->where('team_id', $team->id)
            ->where('purpose', 'renewal')
            ->where('status', 'awaiting_payment');

        if ($expectedChargeMode !== null) {
            $query->where(function ($builder) use ($expectedChargeMode) {
                $builder->whereNull('charge_mode')
                    ->orWhere('charge_mode', '!=', $expectedChargeMode);
            });
        }

        $query->update([
            'status' => 'cancelled',
            'wompi_link_url' => null,
        ]);
    }

    private static function syncSubscriptionIds(Team $team, mixed $planId, mixed $subscriptionId, mixed $status): void
    {
        $planId = trim((string) ($planId ?: $team->n1co_plan_id ?: ''));
        $subscriptionId = trim((string) ($subscriptionId ?: $team->n1co_subscription_id ?: ''));
        $status = trim((string) ($status ?: ''));

        if ($planId === '' && $subscriptionId === '') {
            return;
        }

        $team->forceFill([
            'n1co_plan_id' => $planId !== '' ? $planId : $team->n1co_plan_id,
            'n1co_subscription_id' => $subscriptionId !== '' ? $subscriptionId : $team->n1co_subscription_id,
            'n1co_subscription_status' => $status !== '' ? $status : ($subscriptionId !== '' ? 'active' : $team->n1co_subscription_status),
        ])->save();
    }
}
