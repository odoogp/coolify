<?php

namespace App\Services\GetOdoo;

use App\Models\GetOdooServerOrder;
use App\Models\Server;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SettleGetOdooServerPayment
{
    public function __construct(private ProvisionGetOdooServer $provisioner) {}

    public function settle(GetOdooServerOrder $order, string $transactionId): ?Server
    {
        $claim = DB::transaction(function () use ($order, $transactionId) {
            $fresh = GetOdooServerOrder::query()->whereKey($order->id)->lockForUpdate()->first();

            if (! $fresh instanceof GetOdooServerOrder) {
                return ['state' => 'missing'];
            }

            if (in_array($fresh->status, ['provisioned', 'paid'], true) && $fresh->server_id) {
                return ['state' => 'done', 'server_id' => $fresh->server_id];
            }

            if ($fresh->status === 'provisioning') {
                return ['state' => 'busy'];
            }

            if (! in_array($fresh->status, ['awaiting_payment', 'failed'], true)) {
                return ['state' => 'busy'];
            }

            $fresh->update([
                'status' => 'provisioning',
                'wompi_transaction_id' => $transactionId,
            ]);

            return ['state' => 'claimed'];
        });

        if ($claim['state'] === 'done') {
            return Server::query()->find($claim['server_id']);
        }

        if ($claim['state'] !== 'claimed') {
            return null;
        }

        $order->refresh();

        try {
            if ($order->purpose === 'renewal') {
                $server = $this->renew($order);
                $order->update(['status' => 'paid']);

                return $server;
            }

            $order->load('offer');
            $offer = $order->offer;

            if ($offer === null) {
                throw new RuntimeException('The GetOdoo server offer is gone.');
            }

            $server = $this->provisioner->launch(
                $offer,
                $order->server_name,
                $order->location,
                (int) $order->private_key_id,
                (int) $order->team_id,
                (float) $order->amount,
            );
            $this->extendPaidUntil($server);
            $order->update([
                'server_id' => $server->id,
                'status' => 'provisioned',
            ]);

            return $server;
        } catch (Throwable $exception) {
            $order->update([
                'status' => 'failed',
                'wompi_transaction_id' => null,
            ]);

            throw $exception;
        }
    }

    private function renew(GetOdooServerOrder $order): Server
    {
        $server = Server::query()->find($order->server_id);

        if (! $server instanceof Server || (int) $server->team_id !== (int) $order->team_id || $server->getodoo_offer_id === null) {
            throw new RuntimeException('The GetOdoo subscription is gone.');
        }

        $this->extendPaidUntil($server);

        return $server;
    }

    private function extendPaidUntil(Server $server): void
    {
        $processedAt = now()->startOfDay();
        $anchor = $server->getodoo_billing_anchor?->copy()->startOfDay();

        if (! $anchor instanceof Carbon) {
            $anchor = $server->getodoo_paid_until instanceof Carbon
                ? $server->getodoo_paid_until->copy()->startOfDay()->subMonth()
                : $processedAt;
        }

        $from = $server->getodoo_paid_until instanceof Carbon
            ? $server->getodoo_paid_until->copy()->startOfDay()
            : $anchor->copy();

        $next = $from->startOfMonth()->addMonthsNoOverflow(1);
        $next = $next->day(min($anchor->day, $next->daysInMonth))->startOfDay();

        $server->update([
            'getodoo_billing_anchor' => $anchor->toDateString(),
            'getodoo_paid_until' => $next->toDateString(),
        ]);
    }
}
