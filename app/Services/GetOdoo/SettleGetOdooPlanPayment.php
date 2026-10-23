<?php

namespace App\Services\GetOdoo;

use App\Models\GetOdooPlanOrder;
use App\Models\Team;
use App\Support\GetOdooPlanBilling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SettleGetOdooPlanPayment
{
    public function settle(GetOdooPlanOrder $order, string $transactionId): ?Team
    {
        $claim = DB::transaction(function () use ($order, $transactionId) {
            $fresh = GetOdooPlanOrder::query()->whereKey($order->id)->lockForUpdate()->first();

            if (! $fresh instanceof GetOdooPlanOrder) {
                return ['state' => 'missing'];
            }

            if ($fresh->status === 'paid') {
                return ['state' => 'done', 'team_id' => $fresh->team_id];
            }

            if (! in_array($fresh->status, ['awaiting_payment', 'failed'], true)) {
                return ['state' => 'busy'];
            }

            $fresh->update([
                'status' => 'paid',
                'wompi_transaction_id' => $transactionId,
            ]);

            return ['state' => 'claimed', 'order_id' => $fresh->id];
        });

        if ($claim['state'] === 'done') {
            return Team::query()->find($claim['team_id']);
        }

        if ($claim['state'] !== 'claimed') {
            return null;
        }

        $order->refresh();

        try {
            return $this->renew($order);
        } catch (Throwable $exception) {
            $order->update([
                'status' => 'failed',
                'wompi_transaction_id' => null,
            ]);

            throw $exception;
        }
    }

    public function recordRenewal(GetOdooPlanOrder|Team $target, ?GetOdooPlanOrder $order = null): void
    {
        if ($target instanceof GetOdooPlanOrder) {
            $order = $target;
            $team = $order->team;
        } else {
            $team = $target;
        }

        if (! $team instanceof Team || ! $team->isGetOdooPlanTeam()) {
            return;
        }

        $this->extendPaidUntil($team);
        if ($team->getodooPlan) {
            GetOdooPlanBilling::stampPreference($team, $team->getodooPlan);
        }
        if ($order instanceof GetOdooPlanOrder) {
            GetOdooPlanBilling::syncSubscriptionFromOrder($team, $order);
        }
    }

    private function renew(GetOdooPlanOrder $order): Team
    {
        $team = Team::query()->find($order->team_id);

        if (! $team instanceof Team || ! $team->isGetOdooPlanTeam() || (int) $team->getodoo_plan_id !== (int) $order->plan_id) {
            throw new RuntimeException('The GetOdoo plan subscription is gone.');
        }

        $this->extendPaidUntil($team);
        $plan = $team->getodooPlan;
        if ($plan !== null) {
            GetOdooPlanBilling::stampPreference($team, $plan);
        }
        GetOdooPlanBilling::syncSubscriptionFromOrder($team, $order);

        return $team;
    }

    private function extendPaidUntil(Team $team): void
    {
        $processedAt = now()->startOfDay();
        $anchor = $team->getodoo_plan_billing_anchor?->copy()->startOfDay();

        if (! $anchor instanceof Carbon) {
            $anchor = $team->getodoo_plan_paid_until instanceof Carbon
                ? $team->getodoo_plan_paid_until->copy()->startOfDay()->subMonth()
                : $processedAt;
        }

        $from = $team->getodoo_plan_paid_until instanceof Carbon
            ? $team->getodoo_plan_paid_until->copy()->startOfDay()
            : $anchor->copy();

        $next = $from->startOfMonth()->addMonthsNoOverflow(1);
        $next = $next->day(min($anchor->day, $next->daysInMonth))->startOfDay();

        $team->update([
            'getodoo_plan_billing_anchor' => $anchor->toDateString(),
            'getodoo_plan_paid_until' => $next->toDateString(),
        ]);
    }
}
