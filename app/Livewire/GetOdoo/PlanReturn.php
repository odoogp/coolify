<?php

namespace App\Livewire\GetOdoo;

use App\Models\GetOdooPlanSignup;
use App\Models\User;
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

    public function mount(string $plan, string $signup, WompiClient $wompi, SettleGetOdooPlanSignup $settle): void
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
            report($exception);
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
            report($exception);
            $this->message = __('The account could not be created.');

            return;
        }

        $this->enter($user);
    }

    public function render(): View
    {
        return view('livewire.getodoo.plan-return', [
            'signup' => GetOdooPlanSignup::query()->with('plan')->find($this->signupId),
        ])->layout('layouts.simple');
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
}
