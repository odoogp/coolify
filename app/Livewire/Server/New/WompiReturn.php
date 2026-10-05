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

        if (in_array($order->status, ['provisioned', 'paid'], true) && $order->server) {
            $this->redirectAfterPayment($order);

            return;
        }

        $hash = (string) request()->query('hash', '');

        if ($hash === '') {
            $this->message = $order->status === 'provisioning'
                ? $this->receivedMessage($order)
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
            $this->message = $order->purpose === 'renewal'
                ? __('The monthly charge could not be applied.')
                : __('The GetOdoo server could not be created.');

            return;
        }

        if ($server) {
            $order->refresh();
            $this->redirectAfterPayment($order);

            return;
        }

        $this->message = $this->receivedMessage($order);
    }

    public function refreshStatus(): void
    {
        $order = GetOdooServerOrder::query()->find($this->orderId);

        if (in_array($order?->status, ['provisioned', 'paid'], true) && $order->server) {
            $this->redirectAfterPayment($order);

            return;
        }

        if ($order?->status === 'failed') {
            $this->message = $order->purpose === 'renewal'
                ? __('The monthly charge could not be applied.')
                : __('The GetOdoo server could not be created.');
        }
    }

    private function redirectAfterPayment(GetOdooServerOrder $order): void
    {
        if ($order->purpose === 'renewal') {
            $this->redirectRoute('server.billing');

            return;
        }

        $this->redirectRoute('server.show', ['server_uuid' => $order->server->uuid]);
    }

    private function receivedMessage(GetOdooServerOrder $order): string
    {
        return $order->purpose === 'renewal'
            ? __('Payment received. The subscription is being updated.')
            : __('Payment received. The server is being created.');
    }

    public function render(): View
    {
        return view('livewire.server.new.wompi-return');
    }
}
