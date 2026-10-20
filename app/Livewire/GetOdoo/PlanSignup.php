<?php

namespace App\Livewire\GetOdoo;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanSignup;
use App\Models\GetOdooPricingArea;
use App\Models\User;
use App\Services\GetOdoo\GetOdooCheckout;
use App\Services\GetOdoo\GetOdooPlanPricing;
use App\Services\GetOdoo\OpenGetOdooPlanAccount;
use App\Services\GetOdoo\ResolveGetOdooPlansForCountry;
use App\Support\DetectRequestCountry;
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

    public string $organization = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public ?string $pricingAreaId = null;

    public bool $acceptedTerms = false;

    public string $gateway = 'wompi';

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

    public function register(GetOdooCheckout $checkout, OpenGetOdooPlanAccount $accounts): mixed
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

        $lockedCountry = $this->lockedBuyerCountry($plan);

        $termsRules = [
            'acceptedTerms' => ['accepted'],
        ];

        $accountRules = [
            'name' => ['required', 'string', 'max:255'],
            'organization' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ];

        if ($lockedCountry instanceof GetOdooPricingArea) {
            $this->pricingAreaId = (string) $lockedCountry->id;
            $country = $lockedCountry;
            $this->validate(array_merge($accountRules, [
                'pricingAreaId' => ['required', 'integer', Rule::in([(int) $country->id])],
            ], $termsRules), [
                'acceptedTerms.accepted' => __('You must accept the terms and conditions.'),
                'email.unique' => __('This email is already registered. If you deleted that account, ask an advisor to remove it completely, or use another email.'),
            ]);
        } else {
            // Unknown location, or country not on this plan: standard plan price.
            $this->pricingAreaId = null;
            $country = null;
            $this->validate(array_merge($accountRules, [
                'pricingAreaId' => ['nullable'],
            ], $termsRules), [
                'acceptedTerms.accepted' => __('You must accept the terms and conditions.'),
                'email.unique' => __('This email is already registered. If you deleted that account, ask an advisor to remove it completely, or use another email.'),
            ]);
        }

        RateLimiter::hit($key, 600);
        RateLimiter::hit($ipKey, 600);

        $quote = GetOdooPlanPricing::quote($plan, $country);
        $amount = number_format($quote['amount'], 2, '.', '');

        if ($quote['amount'] <= 0) {
            $user = $accounts->open(
                $this->name,
                $this->email,
                $this->password,
                $plan,
                $country,
                acceptedTerms: true,
                organization: $this->organization,
            );
            $plan->signups()->create([
                'pricing_area_id' => $country?->id,
                'name' => $user->name,
                'organization' => trim($this->organization),
                'email' => $user->email,
                'amount' => 0,
                'payment_gateway' => null,
                'status' => 'paid',
                'user_id' => $user->id,
            ]);

            return $this->enter($user);
        }

        $gateways = $checkout->gateways();

        if ($gateways === []) {
            $this->addError('gateway', __('No payment method is ready.'));

            return null;
        }

        if (count($gateways) === 1) {
            $provider = $gateways[0];
        } elseif (! in_array($this->gateway, $gateways, true)) {
            $this->addError('gateway', __('Choose how to pay.'));

            return null;
        } else {
            $provider = $this->gateway;
        }

        if ($provider === 'transfer') {
            $plan->signups()->create([
                'pricing_area_id' => $country?->id,
                'name' => trim($this->name),
                'organization' => trim($this->organization),
                'email' => strtolower($this->email),
                'amount' => $amount,
                'payment_gateway' => 'transfer',
                'charge_mode' => 'once',
                'status' => 'awaiting_transfer',
            ]);
            $this->addError('gateway', __('Transfer :amount USD using these details. The account opens after the transfer is confirmed.', ['amount' => $amount])."\n\n".$checkout->bankTransfer());

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
                'organization' => trim($this->organization),
                'email' => strtolower($this->email),
                'password' => $this->password,
                'amount' => $amount,
                'payment_gateway' => $provider,
                'charge_mode' => $plan->charge_mode ?: 'once',
                'status' => 'awaiting_payment',
            ]);
        } else {
            $previousGateway = $signup->payment_gateway;
            $previousChargeMode = $signup->charge_mode;
            $chargeMode = $plan->charge_mode ?: 'once';
            $signup->fill([
                'pricing_area_id' => $country?->id,
                'name' => trim($this->name),
                'organization' => trim($this->organization),
                'password' => $this->password,
                'payment_gateway' => $provider,
                'charge_mode' => $chargeMode,
            ]);

            if ($previousGateway !== $provider || $previousChargeMode !== $chargeMode || number_format((float) $signup->amount, 2, '.', '') !== $amount || blank($signup->wompi_link_url)) {
                $signup->forceFill([
                    'uuid' => new_public_id(),
                    'amount' => $amount,
                    'payment_gateway' => $provider,
                    'wompi_link_id' => null,
                    'wompi_link_url' => null,
                    'n1co_plan_id' => null,
                ]);
            }

            $signup->save();
        }

        if (filled($signup->wompi_link_url) && $signup->payment_gateway === $provider) {
            return redirect()->away($signup->wompi_link_url);
        }

        try {
            $link = $checkout->linkForPlan($signup, $provider);
        } catch (Throwable $exception) {
            report($exception);
            $this->addError('gateway', $provider === 'n1co' ? __('n1co is not ready for charges.') : __('Wompi is not ready for charges.'));

            return null;
        }

        $signup->update([
            'payment_gateway' => $provider,
            'wompi_link_id' => $link['id'],
            'wompi_link_url' => $link['url'],
            'n1co_plan_id' => $link['plan_id'] ?? null,
            'charge_mode' => $link['charge_mode'] ?? $signup->charge_mode,
        ]);

        return redirect()->away($link['url']);
    }

    public function render(): View
    {
        $plan = $this->plan();
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
        $checkout = app(GetOdooCheckout::class);
        $gateways = $checkout->gateways();

        if ($gateways !== [] && ! in_array($this->gateway, $gateways, true)) {
            $this->gateway = $gateways[0];
        }

        $region = $lockedCountry?->region();
        $locationLabel = null;
        if ($lockedCountry instanceof GetOdooPricingArea) {
            $locationLabel = $region instanceof GetOdooPricingArea
                ? __(':country · :region', ['country' => $lockedCountry->name, 'region' => $region->name])
                : $lockedCountry->name;
        }

        return view('livewire.getodoo.plan-signup', [
            'plan' => $plan,
            'quote' => $quote,
            'locationLocked' => $locationLocked,
            'locationLabel' => $locationLabel,
            'usesStandardPrice' => ! $locationLocked,
            'canRegister' => ! auth()->check() && User::query()->count() > 0,
            'gateways' => $gateways,
            'bankTransfer' => $checkout->bankTransfer(),
        ])->layout('layouts.simple');
    }

    private function plan(): GetOdooPlan
    {
        return GetOdooPlan::query()->with('pricingAreas')->findOrFail($this->planId);
    }

    /**
     * Country from IP, only when that country (or its region) makes this plan available.
     * Region membership comes from GetOdooPricingArea parent_id.
     */
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
