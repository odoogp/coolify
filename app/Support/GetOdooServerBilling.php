<?php

namespace App\Support;

use App\Models\GetOdooPlan;
use App\Models\GetOdooServerOrder;
use App\Models\Server;

/**
 * Instance charge policy for GetOdoo servers, and the preference stored on each server.
 *
 * - once: every invoice is a one-time checkout link
 * - subscription: every charge (including the first) uses n1co subscription
 * - hybrid: first charge is always once; later invoices use recurring only after the client opts in
 */
class GetOdooServerBilling
{
    public const MODE_ONCE = 'once';

    public const MODE_SUBSCRIPTION = 'subscription';

    public const MODE_HYBRID = 'hybrid';

    public const PREFERENCE_ONCE = 'once';

    public const PREFERENCE_RECURRING = 'recurring';

    /**
     * @return list<string>
     */
    public static function instanceModes(): array
    {
        return [self::MODE_ONCE, self::MODE_SUBSCRIPTION, self::MODE_HYBRID];
    }

    public static function instanceMode(): string
    {
        $mode = (string) (instanceSettings()->n1co_server_charge_mode ?: self::MODE_ONCE);

        return in_array($mode, self::instanceModes(), true) ? $mode : self::MODE_ONCE;
    }

    public static function isHybrid(): bool
    {
        return self::instanceMode() === self::MODE_HYBRID;
    }

    /**
     * Preference stamped on a new server from the instance policy.
     */
    public static function initialPreference(): string
    {
        return self::instanceMode() === self::MODE_SUBSCRIPTION
            ? self::PREFERENCE_RECURRING
            : self::PREFERENCE_ONCE;
    }

    /**
     * Charge mode for a payment link (never returns "hybrid").
     */
    public static function chargeModeForOrder(GetOdooServerOrder $order, ?Server $server = null): string
    {
        $server ??= $order->server;
        $instance = self::instanceMode();

        if ($instance === self::MODE_SUBSCRIPTION) {
            return GetOdooPlan::CHARGE_SUBSCRIPTION;
        }

        // once + hybrid: launch is always a one-time checkout.
        if ($order->purpose === 'launch' || $order->purpose === null) {
            return GetOdooPlan::CHARGE_ONCE;
        }

        // Renewals follow the client preference (Make recurring), deferred until starts_at.
        if ($server instanceof Server && $server->prefersRecurringBilling()) {
            $startsAt = $server->recurringBillingStartsAt();
            if ($startsAt !== null && $startsAt->copy()->startOfDay()->isFuture()) {
                return GetOdooPlan::CHARGE_ONCE;
            }

            return GetOdooPlan::CHARGE_SUBSCRIPTION;
        }

        return GetOdooPlan::CHARGE_ONCE;
    }

    /**
     * Copy n1co subscription ids from a settled order onto the server.
     */
    public static function syncSubscriptionFromOrder(Server $server, GetOdooServerOrder $order): void
    {
        $planId = trim((string) ($order->n1co_plan_id ?: $server->n1co_plan_id ?: ''));
        $subscriptionId = trim((string) ($order->n1co_subscription_id ?: $server->n1co_subscription_id ?: ''));
        $status = trim((string) ($order->n1co_subscription_status ?: ''));

        if ($planId === '' && $subscriptionId === '') {
            return;
        }

        $server->forceFill([
            'n1co_plan_id' => $planId !== '' ? $planId : $server->n1co_plan_id,
            'n1co_subscription_id' => $subscriptionId !== '' ? $subscriptionId : $server->n1co_subscription_id,
            'n1co_subscription_status' => $status !== '' ? $status : ($subscriptionId !== '' ? 'active' : $server->n1co_subscription_status),
        ])->save();
    }

    /**
     * Drop stale awaiting renewal links when the preferred charge mode changes.
     */
    public static function invalidatePendingRenewals(Server $server, ?string $expectedChargeMode = null): void
    {
        $query = GetOdooServerOrder::query()
            ->where('server_id', $server->id)
            ->where('team_id', $server->team_id)
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

    /**
     * Apply (or refresh) the stored preference after a successful payment without wiping a client choice.
     */
    public static function stampPreference(Server $server, bool $forceInitial = false): void
    {
        $current = (string) ($server->getodoo_billing_preference ?: '');

        if (! $forceInitial && in_array($current, [self::PREFERENCE_ONCE, self::PREFERENCE_RECURRING], true)) {
            // Keep client choice; only fill starts_at when recurring and missing.
            if ($server->prefersRecurringBilling() && $server->getodoo_recurring_starts_at === null && $server->getodoo_paid_until !== null) {
                $server->forceFill([
                    'getodoo_recurring_starts_at' => $server->getodoo_paid_until->toDateString(),
                ])->save();
            }

            return;
        }

        $preference = self::initialPreference();
        $startsAt = null;

        if ($preference === self::PREFERENCE_RECURRING) {
            $startsAt = $server->getodoo_paid_until?->toDateString()
                ?? now()->startOfDay()->addMonth()->toDateString();
        }

        $server->forceFill([
            'getodoo_billing_preference' => $preference,
            'getodoo_recurring_starts_at' => $startsAt,
        ])->save();
    }
}
