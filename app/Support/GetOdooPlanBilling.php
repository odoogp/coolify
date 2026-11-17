<?php

namespace App\Support;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanOrder;
use App\Models\GetOdooPlanSignup;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

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

    /**
     * Persist the first plan purchase as a paid invoice line (signup settles without a plan order).
     * Idempotent — safe to call from settlement and from the client Billing screen (backfill).
     */
    public static function ensureSignupOrderRecorded(Team $team): void
    {
        if (! $team->isGetOdooPlanTeam() || $team->getodoo_plan_id === null) {
            return;
        }

        if (GetOdooPlanOrder::query()->where('team_id', $team->id)->where('purpose', 'signup')->exists()) {
            return;
        }

        $memberIds = $team->members()->pluck('users.id');
        if ($memberIds->isEmpty()) {
            return;
        }

        $ownerIds = $team->members()->wherePivot('role', 'owner')->pluck('users.id');

        $signup = null;
        if ($ownerIds->isNotEmpty()) {
            $signup = GetOdooPlanSignup::query()
                ->where('status', 'paid')
                ->where('plan_id', $team->getodoo_plan_id)
                ->whereIn('user_id', $ownerIds)
                ->latest('id')
                ->first();
        }

        if (! $signup instanceof GetOdooPlanSignup) {
            $signup = GetOdooPlanSignup::query()
                ->where('status', 'paid')
                ->where('plan_id', $team->getodoo_plan_id)
                ->whereIn('user_id', $memberIds)
                ->latest('id')
                ->first();
        }

        if (! $signup instanceof GetOdooPlanSignup) {
            $signup = GetOdooPlanSignup::query()
                ->where('status', 'paid')
                ->where('plan_id', $team->getodoo_plan_id)
                ->where('organization', $team->name)
                ->latest('id')
                ->first();
        }

        if (! $signup instanceof GetOdooPlanSignup) {
            return;
        }

        self::recordPaidSignupOrder($team, $signup);
    }

    /**
     * Latest invoice moment for an organization plan: plan order, else billing anchor.
     */
    public static function latestPlanInvoiceDate(Team $team): ?CarbonInterface
    {
        self::ensureSignupOrderRecorded($team);

        $orderAt = GetOdooPlanOrder::query()
            ->where('team_id', $team->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('created_at');

        if ($orderAt !== null) {
            return Carbon::parse($orderAt);
        }

        return $team->getodoo_plan_billing_anchor;
    }

    /**
     * Next charge date: paid_until, else one month after the billing anchor / latest invoice.
     */
    public static function nextPlanBillingDate(Team $team): ?CarbonInterface
    {
        if ($team->getodoo_plan_paid_until instanceof CarbonInterface) {
            return $team->getodoo_plan_paid_until;
        }

        $anchor = $team->getodoo_plan_billing_anchor instanceof CarbonInterface
            ? $team->getodoo_plan_billing_anchor->copy()->startOfDay()
            : null;

        if (! $anchor instanceof CarbonInterface) {
            $invoiceAt = self::latestPlanInvoiceDate($team);
            $anchor = $invoiceAt?->copy()->startOfDay();
        }

        if (! $anchor instanceof CarbonInterface) {
            return null;
        }

        $next = $anchor->copy()->startOfMonth()->addMonthsNoOverflow(1);

        return $next->day(min($anchor->day, $next->daysInMonth))->startOfDay();
    }

    public static function recordPaidSignupOrder(Team $team, GetOdooPlanSignup $signup): GetOdooPlanOrder
    {
        $existing = GetOdooPlanOrder::query()
            ->where('team_id', $team->id)
            ->where('purpose', 'signup')
            ->first();

        if ($existing instanceof GetOdooPlanOrder) {
            return $existing;
        }

        $order = GetOdooPlanOrder::query()->create([
            'team_id' => $team->id,
            'user_id' => $signup->user_id,
            'plan_id' => $signup->plan_id,
            'amount' => $signup->amount,
            'status' => 'paid',
            'purpose' => 'signup',
            'payment_gateway' => $signup->payment_gateway,
            'charge_mode' => $signup->charge_mode ?: GetOdooPlan::CHARGE_ONCE,
            'n1co_plan_id' => $signup->n1co_plan_id,
            'n1co_subscription_id' => $signup->n1co_subscription_id,
            'n1co_subscription_status' => $signup->n1co_subscription_status,
            'wompi_link_id' => $signup->wompi_link_id,
            'wompi_transaction_id' => $signup->wompi_transaction_id,
        ]);

        $paidAt = $signup->updated_at ?? $signup->created_at;
        if ($paidAt !== null) {
            $order->forceFill([
                'created_at' => $paidAt,
                'updated_at' => $paidAt,
            ])->saveQuietly();
        }

        return $order->fresh() ?? $order;
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
