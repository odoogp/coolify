<?php

namespace App\Livewire\Server\New;

use App\Models\GetOdooServerOrder;
use App\Services\GetOdoo\SettleGetOdooServerPayment;
use App\Services\GetOdoo\WompiClient;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class WompiReturn extends Component
{
    #[Locked]
    public int $orderId;

    public string $message = '';

    public function mount(GetOdooServerOrder $order, WompiClient $wompi, SettleGetOdooServerPayment $settle): void
    {
        abort_unless($order->team_id === currentTeam()?->id, 403);
        $this->orderId = $order->id;

        if ($order->status === 'provisioned' && $order->server) {
            $this->redirectRoute('server.show', ['server_uuid' => $order->server->uuid]);

            return;
        }

        $hash = (string) request()->query('hash', '');

        if ($hash === '') {
            $this->message = $order->status === 'provisioning'
                ? __('Payment received. The server is being created.')
                : __('This payment could not be confirmed.');

            return;
        }

        $transactionId = (string) request()->query('idTransaccion', '');
        $linkId = (string) request()->query('idEnlace', '');
        $amount = (string) request()->query('monto', '');
        $expected = $wompi->paymentLinkHash($order->uuid, $transactionId, $linkId, $amount);

        if (! $wompi->configured() || ! $wompi->hashMatches($expected, $hash) || $transactionId === '') {
            abort(403);
        }

        if (! $wompi->sameMoney($amount, $order->amount)) {
            $this->message = __('This payment could not be confirmed.');

            return;
        }

        try {
            $transaction = $wompi->transaction($transactionId);
        } catch (Throwable $exception) {
            report($exception);
            $this->message = __('This payment could not be confirmed.');

            return;
        }

        if (! $wompi->chargeIsLiveAndApproved($transaction, $order)) {
            $this->message = $wompi->isTestCharge($transaction)
                ? __('Wompi marked this charge as a test. The server is created only after a live charge.')
                : __('This payment was not approved.');

            return;
        }

        try {
            $server = $settle->settle($order, $transactionId);
        } catch (Throwable $exception) {
            report($exception);
            $this->message = __('The GetOdoo server could not be created.');

            return;
        }

        if ($server) {
            $this->redirectRoute('server.show', ['server_uuid' => $server->uuid]);

            return;
        }

        $this->message = __('Payment received. The server is being created.');
    }

    public function refreshStatus(): void
    {
        $order = GetOdooServerOrder::query()->find($this->orderId);

        if ($order?->status === 'provisioned' && $order->server) {
            $this->redirectRoute('server.show', ['server_uuid' => $order->server->uuid]);
        }

        if ($order?->status === 'failed') {
            $this->message = __('The GetOdoo server could not be created.');
        }
    }

    public function render(): View
    {
        return view('livewire.server.new.wompi-return');
    }
}
