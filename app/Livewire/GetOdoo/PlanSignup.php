<?php

namespace App\Livewire\GetOdoo;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanSignup;
use App\Models\User;
use App\Services\GetOdoo\OpenGetOdooPlanAccount;
use App\Services\GetOdoo\WompiClient;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

class PlanSignup extends Component
{
    #[Locked]
    public int $planId;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public function mount(string $plan): void
    {
        $found = GetOdooPlan::query()->where('uuid', $plan)->where('is_active', true)->first();
        abort_unless($found instanceof GetOdooPlan, 404);
        $this->planId = $found->id;
    }

    public function register(WompiClient $wompi, OpenGetOdooPlanAccount $accounts): mixed
    {
        $plan = $this->plan();

        if (auth()->check() || User::query()->count() === 0) {
            return null;
        }

        $key = 'plan-signup:'.sha1((string) request()->ip().'|'.strtolower($this->email));
        $ipKey = 'plan-signup-ip:'.sha1((string) request()->ip());

        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($ipKey, 20)) {
            $this->addError('email', __('Too many registration attempts. Please try again later.'));

            return null;
        }

        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        RateLimiter::hit($key, 600);
        RateLimiter::hit($ipKey, 600);

        if ($plan->isFree()) {
            $user = $accounts->open($this->name, $this->email, $this->password, $plan);
            $plan->signups()->create([
                'name' => $user->name,
                'email' => $user->email,
                'amount' => 0,
                'payment_gateway' => null,
                'status' => 'paid',
                'user_id' => $user->id,
            ]);

            return $this->enter($user);
        }

        $gateway = $plan->payment_gateway ?: 'wompi';

        if ($gateway !== 'wompi') {
            $this->addError('email', __('This payment gateway is not ready.'));

            return null;
        }

        if (! $wompi->configured()) {
            $this->addError('email', __('Wompi is not ready for charges.'));

            return null;
        }

        $amount = number_format((float) $plan->price, 2, '.', '');
        $signup = GetOdooPlanSignup::query()
            ->where('plan_id', $plan->id)
            ->where('email', strtolower($this->email))
            ->where('status', 'awaiting_payment')
            ->latest('id')
            ->first();

        if (! $signup instanceof GetOdooPlanSignup) {
            $signup = $plan->signups()->create([
                'name' => trim($this->name),
                'email' => strtolower($this->email),
                'password' => $this->password,
                'amount' => $amount,
                'payment_gateway' => 'wompi',
                'status' => 'awaiting_payment',
            ]);
        } else {
            $signup->fill([
                'name' => trim($this->name),
                'password' => $this->password,
            ]);

            if (number_format((float) $signup->amount, 2, '.', '') !== $amount || blank($signup->wompi_link_url)) {
                $signup->forceFill([
                    'uuid' => new_public_id(),
                    'amount' => $amount,
                    'wompi_link_id' => null,
                    'wompi_link_url' => null,
                ]);
            }

            $signup->save();
        }

        if (filled($signup->wompi_link_url)) {
            return redirect()->away($signup->wompi_link_url);
        }

        try {
            $link = $wompi->createPlanLink($signup);
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('email', __('Wompi is not ready for charges.'));

            return null;
        }

        $signup->update([
            'wompi_link_id' => $link['id'],
            'wompi_link_url' => $link['url'],
        ]);

        return redirect()->away($link['url']);
    }

    public function render(): View
    {
        return view('livewire.getodoo.plan-signup', [
            'plan' => $this->plan(),
            'canRegister' => ! auth()->check() && User::query()->count() > 0,
        ])->layout('layouts.simple');
    }

    private function plan(): GetOdooPlan
    {
        return GetOdooPlan::query()->findOrFail($this->planId);
    }

    private function enter(User $user): mixed
    {
        Auth::login($user);
        session(['currentTeam' => $user->teams()->first()]);

        return redirect()->route('dashboard');
    }
}
