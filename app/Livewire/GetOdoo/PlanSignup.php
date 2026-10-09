<?php

namespace App\Livewire\GetOdoo;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanSignup;
use App\Models\GetOdooPricingArea;
use App\Models\User;
use App\Services\GetOdoo\GetOdooPlanPricing;
use App\Services\GetOdoo\OpenGetOdooPlanAccount;
use App\Services\GetOdoo\ResolveGetOdooPlansForCountry;
use App\Services\GetOdoo\WompiClient;
use App\Support\DetectRequestCountry;
use App\Support\GetOdooCountries;
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

    public ?string $pricingAreaId = null;

    public function mount(string $plan): void
    {
        $found = GetOdooPlan::query()->where('uuid', $plan)->where('is_active', true)->first();
        abort_unless($found instanceof GetOdooPlan, 404);
        $this->planId = $found->id;

        $country = $this->lockedBuyerCountry($found);
        if ($country instanceof GetOdooPricingArea) {
            $this->pricingAreaId = (string) $country->id;
        }
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

        $buyerIso = DetectRequestCountry::iso();
        $lockedCountry = $this->lockedBuyerCountry($plan);

        if ($buyerIso !== null && $lockedCountry === null) {
            $this->addError(
                'pricingAreaId',
                __('This plan is not available in :country. Contact an advisor.', [
                    'country' => GetOdooCountries::name($buyerIso) ?? $buyerIso,
                ])
            );

            return null;
        }

        if ($lockedCountry instanceof GetOdooPricingArea) {
            $this->pricingAreaId = (string) $lockedCountry->id;
            $country = $lockedCountry;
            $this->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
                'password' => ['required', Password::defaults(), 'confirmed'],
                'pricingAreaId' => ['required', 'integer', Rule::in([(int) $country->id])],
            ]);
        } else {
            // Location unknown: standard plan price, no country extras / promo.
            $this->pricingAreaId = null;
            $country = null;
            $this->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
                'password' => ['required', Password::defaults(), 'confirmed'],
                'pricingAreaId' => ['nullable'],
            ]);
        }

        RateLimiter::hit($key, 600);
        RateLimiter::hit($ipKey, 600);

        $quote = GetOdooPlanPricing::quote($plan, $country);
        $amount = number_format($quote['amount'], 2, '.', '');

        if ($quote['amount'] <= 0) {
            $user = $accounts->open($this->name, $this->email, $this->password, $plan, $country);
            $plan->signups()->create([
                'pricing_area_id' => $country?->id,
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

        $signup = GetOdooPlanSignup::query()
            ->where('plan_id', $plan->id)
            ->where('email', strtolower($this->email))
            ->where('status', 'awaiting_payment')
            ->latest('id')
            ->first();

        if (! $signup instanceof GetOdooPlanSignup) {
            $signup = $plan->signups()->create([
                'pricing_area_id' => $country?->id,
                'name' => trim($this->name),
                'email' => strtolower($this->email),
                'password' => $this->password,
                'amount' => $amount,
                'payment_gateway' => 'wompi',
                'status' => 'awaiting_payment',
            ]);
        } else {
            $signup->fill([
                'pricing_area_id' => $country?->id,
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
        $plan = $this->plan();
        $buyerIso = DetectRequestCountry::iso();
        $lockedCountry = $this->lockedBuyerCountry($plan);
        $locationLocked = $lockedCountry instanceof GetOdooPricingArea;

        if ($locationLocked) {
            $this->pricingAreaId = (string) $lockedCountry->id;
            $country = $lockedCountry;
        } else {
            $this->pricingAreaId = null;
            $country = null;
        }

        $quote = GetOdooPlanPricing::quote($plan, $country);
        $locationBlockedMessage = null;
        if ($buyerIso !== null && ! $locationLocked) {
            $locationBlockedMessage = __('This plan is not available in :country. Contact an advisor.', [
                'country' => GetOdooCountries::name($buyerIso) ?? $buyerIso,
            ]);
        }

        $canRegister = ! auth()->check()
            && User::query()->count() > 0
            && $locationBlockedMessage === null;

        return view('livewire.getodoo.plan-signup', [
            'plan' => $plan,
            'quote' => $quote,
            'locationLocked' => $locationLocked,
            'locationLabel' => $lockedCountry?->name,
            'locationBlockedMessage' => $locationBlockedMessage,
            'usesStandardPrice' => ! $locationLocked && $buyerIso === null,
            'canRegister' => $canRegister,
        ])->layout('layouts.simple');
    }

    private function plan(): GetOdooPlan
    {
        return GetOdooPlan::query()->with('pricingAreas')->findOrFail($this->planId);
    }

    private function lockedBuyerCountry(GetOdooPlan $plan): ?GetOdooPricingArea
    {
        $country = GetOdooPricingArea::findCountryByIso(DetectRequestCountry::iso());
        if (! $country instanceof GetOdooPricingArea) {
            return null;
        }

        if (! ResolveGetOdooPlansForCountry::planIsAvailable($plan, $country)) {
            return null;
        }

        return $country;
    }

    private function enter(User $user): mixed
    {
        Auth::login($user);
        $team = $user->teams()->where('getodoo_plan_id', $this->planId)->first()
            ?? $user->teams()->first();
        session(['currentTeam' => $team]);

        return redirect()->route('dashboard');
    }
}
