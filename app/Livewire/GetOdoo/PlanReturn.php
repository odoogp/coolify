<?php

namespace App\Livewire\GetOdoo;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanSignup;
use App\Models\User;
use App\Services\GetOdoo\N1coClient;
use App\Services\GetOdoo\SettleGetOdooPlanSignup;
use App\Services\GetOdoo\WompiClient;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class PlanReturn extends Component
{
    #[Locked]
    public int $signupId;

    public string $message = '';

    public function mount(string $plan, string $signup, WompiClient $wompi, N1coClient $n1co, SettleGetOdooPlanSignup $settle): void
    {
        $found = GetOdooPlanSignup::query()
            ->where('uuid', $signup)
            ->whereHas('plan', fn ($query) => $query->where('uuid', $plan))
            ->first();

        abort_unless($found instanceof GetOdooPlanSignup, 404);
        $this->signupId = $found->id;

        if ($found->status === 'paid' && $found->user instanceof User) {
            $this->enter($found->user);

            return;
        }

        if ($found->payment_gateway === 'n1co') {
            $this->confirmN1co($found, $n1co, $settle);

            return;
        }

        $hash = (string) request()->query('hash', '');
        $transactionId = (string) request()->query('idTransaccion', '');
        $linkId = (string) request()->query('idEnlace', '');
        $amount = (string) request()->query('monto', '');

        if ($hash === '' || $transactionId === '' || ! $wompi->configured()) {
            $this->message = __('This payment could not be confirmed.');

            return;
        }

        $expected = $wompi->paymentLinkHash($found->uuid, $transactionId, $linkId, $amount);

        if (! $wompi->hashMatches($expected, $hash) || ! $wompi->sameMoney($amount, $found->amount)) {
            abort(403);
        }

        try {
            $transaction = $wompi->transaction($transactionId);
        } catch (Throwable $exception) {
            $this->safeReport($exception);
            $this->message = __('This payment could not be confirmed.');

            return;
        }

        if (! $wompi->liveApprovedAmount($transaction, $found->amount)) {
            $this->message = $wompi->isTestCharge($transaction)
                ? __('Wompi marked this charge as a test. The account is created only after a live charge.')
                : __('This payment was not approved.');

            return;
        }

        try {
            $user = $settle->settle($found, $transactionId);
        } catch (Throwable $exception) {
            $this->safeReport($exception);
            $this->message = __('The account could not be created.');

            return;
        }

        $this->enter($user);
    }

    public function refreshStatus(N1coClient $n1co, SettleGetOdooPlanSignup $settle): void
    {
        $signup = GetOdooPlanSignup::query()->find($this->signupId);

        if (! $signup instanceof GetOdooPlanSignup) {
            return;
        }

        if ($signup->status === 'paid' && $signup->user instanceof User) {
            $this->enter($signup->user);

            return;
        }

        if ($signup->payment_gateway === 'n1co') {
            $this->confirmN1co($signup, $n1co, $settle);
        }
    }

    public function render(): View
    {
        return view('livewire.getodoo.plan-return', [
            'signup' => GetOdooPlanSignup::query()->with('plan')->find($this->signupId),
        ])->layout('layouts.simple');
    }

    private function confirmN1co(GetOdooPlanSignup $signup, N1coClient $n1co, SettleGetOdooPlanSignup $settle): void
    {
        try {
            $this->confirmN1coUnsafe($signup, $n1co, $settle);
        } catch (Throwable $exception) {
            $this->safeReport($exception);
            $this->message = $this->waitingForN1coMessage($signup);
        }
    }

    private function confirmN1coUnsafe(GetOdooPlanSignup $signup, N1coClient $n1co, SettleGetOdooPlanSignup $settle): void
    {
        if (! $n1co->canChargePlans() && $signup->charge_mode !== GetOdooPlan::CHARGE_SUBSCRIPTION) {
            $this->message = $this->waitingForN1coMessage($signup);

            return;
        }

        // Monthly subscriptions are confirmed only via the Plans API / webhooks.
        // Do not call the checkout payment-link API here: a bad checkout secret
        // (or report() failing on laravel.log) was turning "still waiting" into HTTP 500.
        if ($signup->charge_mode === GetOdooPlan::CHARGE_SUBSCRIPTION) {
            if (! filled($signup->n1co_plan_id)) {
                $this->message = $this->waitingForN1coMessage($signup);

                return;
            }

            $subscription = $n1co->paidSubscription($signup->n1co_plan_id, $signup->amount);

            if (! is_array($subscription)) {
                $this->message = $this->waitingForN1coMessage($signup);

                return;
            }

            $subscriptionId = trim((string) ($subscription['subscriptionId'] ?? ''));

            try {
                $user = $settle->settle($signup, $subscriptionId !== '' ? $subscriptionId : 'n1co-'.$signup->uuid);
                $signup->update([
                    'n1co_subscription_id' => $subscriptionId !== '' ? $subscriptionId : $signup->n1co_subscription_id,
                    'n1co_subscription_status' => 'active',
                ]);
            } catch (Throwable $exception) {
                $this->safeReport($exception);
                $this->message = __('The account could not be created.');

                return;
            }

            $this->enter($user);

            return;
        }

        if (! $n1co->canChargePlans()) {
            $this->message = $this->waitingForN1coMessage($signup);

            return;
        }

        $codes = $n1co->candidateOrderCodes($signup->wompi_link_id, $signup->wompi_link_url, request()->query());
        $remote = $n1co->paidOrder($codes, $signup->amount, $signup->uuid);

        if (! is_array($remote)) {
            $this->message = $this->waitingForN1coMessage($signup);

            return;
        }

        $code = $n1co->authorizationCode($remote) !== ''
            ? (string) ($remote['orderCode'] ?? $remote['OrderCode'] ?? $codes[0] ?? '')
            : (string) ($codes[0] ?? '');
        $transactionId = $n1co->authorizationCode($remote);

        try {
            $user = $settle->settle($signup, $transactionId !== '' ? $transactionId : 'n1co-'.$code);
        } catch (Throwable $exception) {
            $this->safeReport($exception);
            $this->message = __('The account could not be created.');

            return;
        }

        $this->enter($user);
    }

    private function waitingForN1coMessage(GetOdooPlanSignup $signup): string
    {
        if ($signup->charge_mode === GetOdooPlan::CHARGE_SUBSCRIPTION && filled($signup->wompi_link_url)) {
            return __('Pay with n1co using the button below. Keep this page open — your account opens here when the payment is confirmed.');
        }

        return __('n1co has not confirmed this payment yet.');
    }

    private function enter(User $user): void
    {
        Auth::login($user);
        $planId = GetOdooPlanSignup::query()->whereKey($this->signupId)->value('plan_id');
        $team = $user->teams()->where('getodoo_plan_id', $planId)->first()
            ?? $user->teams()->first();
        session(['currentTeam' => $team]);
        $this->redirectRoute('dashboard');
    }

    private function safeReport(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            error_log('getodoo plan return: report failed: '.$exception->getMessage());
        }
    }
}
