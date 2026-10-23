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
use Illuminate\Support\Facades\Log;
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
        Log::stack(['single', 'stderr'])->info('getodoo plan register invoked', [
            'plan_id' => $this->planId,
            'email' => strtolower(trim($this->email)),
            'gateway' => $this->gateway,
        ]);
        error_log('getodoo plan register invoked plan_id='.$this->planId.' gateway='.$this->gateway);

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

        User::releaseEmailForRegistration($this->email);

        $termsRules = [
            'acceptedTerms' => ['accepted'],
        ];

        // Do not use Password::defaults() here: in production that includes
        // uncompromised() (Have I Been Pwned). If that API is slow/blocked, the
        // Livewire button spins forever and n1co is never called — no n1co logs.
        $accountRules = [
            'name' => ['required', 'string', 'max:255'],
            'organization' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->letters()->numbers()->symbols()],
        ];

        if ($lockedCountry instanceof GetOdooPricingArea) {
            $this->pricingAreaId = (string) $lockedCountry->id;
            $country = $lockedCountry;
            $this->validate(array_merge($accountRules, [
                'pricingAreaId' => ['required', 'integer', Rule::in([(int) $country->id])],
            ], $termsRules), [
                'acceptedTerms.accepted' => __('You must accept the terms and conditions.'),
                'email.unique' => __('This email is already registered.'),
            ]);
        } else {
            // Unknown location, or country not on this plan: standard plan price.
            $this->pricingAreaId = null;
            $country = null;
            $this->validate(array_merge($accountRules, [
                'pricingAreaId' => ['nullable'],
            ], $termsRules), [
                'acceptedTerms.accepted' => __('You must accept the terms and conditions.'),
                'email.unique' => __('This email is already registered.'),
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
            return $this->redirectToPayment((string) $signup->wompi_link_url);
        }

        try {
            Log::stack(['single', 'stderr'])->info('getodoo plan payment starting', [
                'provider' => $provider,
                'plan_uuid' => $plan->uuid,
                'signup_uuid' => $signup->uuid,
                'charge_mode' => $signup->charge_mode,
            ]);
            error_log('getodoo plan payment starting provider='.$provider.' signup='.$signup->uuid);

            $link = $checkout->linkForPlan($signup, $provider);
            $url = trim((string) ($link['url'] ?? ''));
            if ($url === '') {
                throw new \RuntimeException(
                    $provider === 'n1co'
                        ? __('n1co did not return a payment link.')
                        : __('Wompi did not return a payment link.')
                );
            }

            $signup->update([
                'payment_gateway' => $provider,
                'wompi_link_id' => $link['id'] ?? null,
                'wompi_link_url' => $url,
                'n1co_plan_id' => $link['plan_id'] ?? null,
                'charge_mode' => $link['charge_mode'] ?? $signup->charge_mode,
            ]);

            return $this->redirectToPayment($url);
        } catch (Throwable $exception) {
            $context = [
                'provider' => $provider,
                'plan_id' => $plan->id,
                'plan_uuid' => $plan->uuid,
                'signup_id' => $signup->id,
                'signup_uuid' => $signup->uuid,
                'email' => $signup->email,
                'amount' => (string) $signup->amount,
                'charge_mode' => $signup->charge_mode,
                'exception' => $exception->getMessage(),
                'exception_class' => $exception::class,
            ];
            Log::stack(['single', 'stderr'])->error('getodoo plan payment link failed', $context);
            error_log('getodoo plan payment link failed '.json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            report($exception);

            $message = trim($exception->getMessage());
            if ($message === '' || ! str_contains(strtolower($message), $provider === 'n1co' ? 'n1co' : 'wompi')) {
                $message = $provider === 'n1co'
                    ? __('n1co is not ready for charges.')
                    : __('Wompi is not ready for charges.');
            }
            $this->addError('gateway', $message);
            $this->dispatch('error', $message);

            return null;
        }
    }

    private function redirectToPayment(string $url): mixed
    {
        // Livewire needs $this->redirect() so the browser actually leaves the page.
        $this->redirect($url);

        return null;
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
            // Prefer n1co when it is available so "Continue to payment" does not keep a dead Wompi default.
            $this->gateway = in_array('n1co', $gateways, true) ? 'n1co' : $gateways[0];
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
