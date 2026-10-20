<?php

namespace App\Livewire\Server\New;

use App\Models\GetOdooServerOrder;
use App\Services\GetOdoo\N1coClient;
use App\Services\GetOdoo\SettleGetOdooServerPayment;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class N1coReturn extends Component
{
    #[Locked]
    public int $orderId;

    public string $message = '';

    public function mount(GetOdooServerOrder $order, N1coClient $n1co, SettleGetOdooServerPayment $settle): void
    {
        abort_unless($order->team_id === currentTeam()?->id, 403);
        $this->orderId = $order->id;

        if (in_array($order->status, ['provisioned', 'paid'], true) && $order->server) {
            $this->redirectAfterPayment($order);

            return;
        }

        if (request()->boolean('cancelled')) {
            $this->message = __('The payment was cancelled.');

            return;
        }

        $this->confirm($order, $n1co, $settle);
    }

    public function refreshStatus(N1coClient $n1co, SettleGetOdooServerPayment $settle): void
    {
        $order = GetOdooServerOrder::query()->find($this->orderId);

        if (! $order instanceof GetOdooServerOrder) {
            return;
        }

        if (in_array($order->status, ['provisioned', 'paid'], true) && $order->server) {
            $this->redirectAfterPayment($order);

            return;
        }

        if ($order->status === 'failed') {
            $this->message = $order->purpose === 'renewal'
                ? __('The monthly charge could not be applied.')
                : __('The GetOdoo server could not be created.');

            return;
        }

        if (request()->boolean('cancelled')) {
            return;
        }

        $this->confirm($order, $n1co, $settle);
    }

    public function render(): View
    {
        return view('livewire.server.new.wompi-return');
    }

    private function confirm(GetOdooServerOrder $order, N1coClient $n1co, SettleGetOdooServerPayment $settle): void
    {
        if (! $n1co->canChargePlans()) {
            $this->message = __('n1co has not confirmed this payment yet.');

            return;
        }

        $codes = $n1co->candidateOrderCodes($order->wompi_link_id, $order->wompi_link_url, request()->query());
        $remote = $n1co->paidOrder($codes, $order->amount, $order->uuid);

        if (! is_array($remote)) {
            $this->message = __('n1co has not confirmed this payment yet.');

            return;
        }

        $code = (string) ($codes[0] ?? '');
        $transactionId = $n1co->authorizationCode($remote);

        try {
            $server = $settle->settle($order, $transactionId !== '' ? $transactionId : 'n1co-'.$code);
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

        $this->message = $order->purpose === 'renewal'
            ? __('Payment received. The subscription is being updated.')
            : __('Payment received. The server is being created.');
    }

    private function redirectAfterPayment(GetOdooServerOrder $order): void
    {
        if ($order->purpose === 'renewal') {
            $this->redirectRoute('server.billing');

            return;
        }

        $this->redirectRoute('server.show', ['server_uuid' => $order->server->uuid]);
    }
}
