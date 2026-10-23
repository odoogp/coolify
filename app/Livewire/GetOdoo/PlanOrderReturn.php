<?php

namespace App\Livewire\GetOdoo;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanOrder;
use App\Services\GetOdoo\N1coClient;
use App\Services\GetOdoo\SettleGetOdooPlanPayment;
use App\Services\GetOdoo\WompiClient;
use App\Support\GetOdooPlanBilling;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class PlanOrderReturn extends Component
{
    #[Locked]
    public int $orderId;

    public string $message = '';

    public function mount(GetOdooPlanOrder $order, N1coClient $n1co, WompiClient $wompi, SettleGetOdooPlanPayment $settle): void
    {
        abort_unless($order->team_id === currentTeam()?->id, 403);
        $this->orderId = $order->id;

        if ($order->status === 'paid') {
            $this->redirect(route('server.billing'));

            return;
        }

        if (request()->boolean('cancelled')) {
            $this->message = __('The payment was cancelled.');

            return;
        }

        $this->confirm($order, $n1co, $wompi, $settle);
    }

    public function refreshStatus(N1coClient $n1co, WompiClient $wompi, SettleGetOdooPlanPayment $settle): void
    {
        $order = GetOdooPlanOrder::query()->find($this->orderId);

        if (! $order instanceof GetOdooPlanOrder) {
            return;
        }

        if ($order->status === 'paid') {
            $this->redirect(route('server.billing'));

            return;
        }

        if ($order->status === 'failed') {
            $this->message = __('The monthly charge could not be applied.');

            return;
        }

        $this->confirm($order, $n1co, $wompi, $settle);
    }

    public function render(): View
    {
        $order = GetOdooPlanOrder::query()->find($this->orderId);

        return view('livewire.server.new.wompi-return', [
            'order' => $order,
            'paymentUrl' => $order instanceof GetOdooPlanOrder
                && $order->status === 'awaiting_payment'
                && filled($order->wompi_link_url)
                ? (string) $order->wompi_link_url
                : null,
        ]);
    }

    private function confirm(GetOdooPlanOrder $order, N1coClient $n1co, WompiClient $wompi, SettleGetOdooPlanPayment $settle): void
    {
        try {
            if ($order->charge_mode === GetOdooPlan::CHARGE_SUBSCRIPTION) {
                if (! filled($order->n1co_plan_id)) {
                    $this->message = __('n1co has not confirmed this payment yet.');

                    return;
                }

                $subscription = $n1co->paidSubscription($order->n1co_plan_id, $order->amount);
                if (! is_array($subscription)) {
                    $this->message = __('n1co has not confirmed this payment yet.');

                    return;
                }

                $subscriptionId = trim((string) ($subscription['subscriptionId'] ?? ''));
                $team = $settle->settle($order, $subscriptionId !== '' ? $subscriptionId : 'n1co-'.$order->uuid);
                $order->update([
                    'n1co_subscription_id' => $subscriptionId !== '' ? $subscriptionId : $order->n1co_subscription_id,
                    'n1co_subscription_status' => 'active',
                ]);
                if ($team) {
                    GetOdooPlanBilling::syncSubscriptionFromOrder($team, $order->fresh());
                    $this->redirect(route('server.billing'));
                }

                return;
            }

            if (($order->payment_gateway ?: '') === 'n1co' && filled($order->wompi_link_id) && $n1co->configured()) {
                $remote = $n1co->order((string) $order->wompi_link_id);
                if (! $n1co->isPaid($remote, $order->amount)) {
                    $this->message = __('n1co has not confirmed this payment yet.');

                    return;
                }
                $settle->settle($order, 'n1co-'.$order->uuid);
                $this->redirect(route('server.billing'));

                return;
            }

            $this->message = __('Waiting for the payment confirmation…');
        } catch (Throwable $exception) {
            try {
                report($exception);
            } catch (Throwable) {
            }
            $this->message = __('n1co has not confirmed this payment yet.');
        }
    }
}
