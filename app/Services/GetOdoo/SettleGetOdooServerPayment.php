<?php

namespace App\Services\GetOdoo;

use App\Models\GetOdooServerOrder;
use App\Models\Server;
use Illuminate\Support\Facades\DB;
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

            if ($fresh->status === 'provisioned' && $fresh->server_id) {
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

        $order->refresh()->load('offer');
        $offer = $order->offer;

        if ($offer === null) {
            $order->update(['status' => 'failed']);

            throw new \RuntimeException('The GetOdoo server offer is gone.');
        }

        try {
            $server = $this->provisioner->launch(
                $offer,
                $order->server_name,
                $order->location,
                (int) $order->private_key_id,
                (int) $order->team_id,
                (float) $order->amount,
            );
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
}
