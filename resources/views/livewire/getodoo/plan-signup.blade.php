<x-auth.shell wide scroll :title="product_name()"
    :description="__('Create the admin account for this package.')">
    <div class="auth-plan-layout">
        <aside class="auth-plan-package">
            <p class="auth-plan-kicker">{{ __('Your plan') }}</p>
            <h2 class="auth-plan-name">{{ $plan->name }}</h2>
            <p class="auth-plan-price">
                @if ($quote['amount'] <= 0)
                    {{ __('Free') }}
                @else
                    ${{ number_format((float) $quote['amount'], 2) }}
                    <span>{{ __('per month') }}</span>
                @endif
            </p>
            @if ($quote['amount'] > 0 && ($quote['promo_price'] ?? null) !== null)
                <p class="auth-plan-copy">
                    {{ __('Promotional price for your country.') }}
                </p>
            @elseif ($quote['amount'] > 0 && ($quote['extra_fixed'] > 0 || $quote['extra_percent'] > 0))
                <p class="auth-plan-copy">
                    {{ __('Base :base. Country extras included.', ['base' => '$'.number_format((float) $quote['base'], 2)]) }}
                </p>
            @endif
            @if (filled($plan->summary))
                <p class="auth-plan-copy">{{ $plan->summary }}</p>
            @endif

            <div class="auth-plan-includes">
                <p class="auth-plan-kicker">{{ __('What is included') }}</p>
                @foreach (preg_split('/\R/u', (string) $plan->description) ?: [] as $line)
                    @if (filled(trim($line)))
                        <div class="auth-plan-feature">
                            <x-reicon name="check-circle" class="auth-plan-check" />
                            <span>{{ trim($line) }}</span>
                        </div>
                    @endif
                @endforeach
                @foreach ($plan->includedItems() as $item)
                    <div class="auth-plan-include">
                        <span class="flex min-w-0 items-center gap-2">
                            <x-reicon name="check-circle" class="auth-plan-check" />
                            <span>{{ $item['label'] }}</span>
                        </span>
                        @if (filled($item['value']))
                            <span class="auth-plan-value">{{ $item['value'] }}</span>
                        @endif
                    </div>
                @endforeach
            </div>

            <p class="auth-plan-copy">
                @if ($quote['amount'] <= 0)
                    {{ __('This package has no charge.') }}
                @elseif ($usesStandardPrice ?? false)
                    {{ __('This is the standard monthly plan price. This step pays the first charge with Wompi.') }}
                @else
                    {{ __('This is the monthly price for your location. This step pays the first charge with Wompi.') }}
                @endif
            </p>
        </aside>

        <div class="auth-plan-account">
            <div>
                <h2 class="text-base font-semibold">{{ __('Admin account') }}</h2>
                <p class="mt-1 text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                    {{ __('This account manages the package.') }}
                </p>
            </div>

            @if (auth()->check())
                <x-auth.alert type="warning">
                    <p>{{ __('You already have an account.') }}</p>
                </x-auth.alert>
                <a href="{{ route('dashboard') }}" class="button button-highlighted w-full justify-center">
                    {{ __('Go to the dashboard') }}
                </a>
            @elseif (! $canRegister)
                <x-auth.alert type="warning">
                    <p>{{ __('This instance is not ready for customers yet.') }}</p>
                </x-auth.alert>
            @else
                <form class="flex flex-col gap-4" wire:submit="register">
                    <x-forms.input id="name" required type="text" autocomplete="name" autofocus
                        label="{{ __('input.name') }}" />
                    <x-forms.input id="email" required type="email" autocomplete="email"
                        label="{{ __('input.email') }}" />
                    @if ($locationLocked)
                        <div>
                            <p class="mb-1 text-[13px] font-medium text-neutral-700 dark:text-fg">{{ __('Country') }}</p>
                            <p
                                class="rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 text-[13px] text-neutral-800 dark:border-white/[0.08] dark:bg-white/[0.03] dark:text-fg">
                                {{ $locationLabel }}
                            </p>
                            <p class="mt-1 text-[12px] leading-4 text-neutral-500 dark:text-fg-dim">
                                {{ __('Detected from your location. The price for this country applies.') }}
                            </p>
                            @error('pricingAreaId')
                                <p class="mt-1 text-[12px] text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif
                    <x-forms.input id="password" required type="password" autocomplete="new-password"
                        label="{{ __('input.password') }}" />
                    <x-forms.input id="password_confirmation" required type="password" autocomplete="new-password"
                        label="{{ __('input.password.again') }}" />

                    <div class="auth-guidance">
                        <x-reicon name="info-circle" class="mt-0.5 size-4 shrink-0" />
                        <p>{{ __('Use at least 8 characters with uppercase, lowercase, number, and symbol.') }}</p>
                    </div>

                    <div>
                        <label class="flex items-start gap-2 text-[13px] leading-5 text-neutral-700 dark:text-fg">
                            <input type="checkbox" class="mt-0.5 rounded" wire:model="acceptedTerms">
                            <span>
                                {{ __('I have read and accept the') }}
                                <a href="{{ route('getodoo.terms') }}" target="_blank" rel="noopener noreferrer"
                                    class="font-medium text-coollabs underline decoration-coollabs/30 underline-offset-2 hover:decoration-coollabs dark:text-warning dark:decoration-warning/30 dark:hover:decoration-warning">
                                    {{ __('terms and conditions') }}
                                </a>.
                            </span>
                        </label>
                        @error('acceptedTerms')
                            <p class="mt-1 text-[12px] text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <x-forms.button class="w-full justify-center" type="submit" isHighlighted>
                        {{ $quote['amount'] <= 0 ? __('Create account') : __('Continue to payment') }}
                    </x-forms.button>
                </form>
            @endif
        </div>
    </div>

    @if (! auth()->check())
        <x-slot:footer>
            <span>{{ __('Already have an account?') }}</span>
            <a href="{{ route('login') }}" class="auth-text-link underline">{{ __('auth.already_registered') }}</a>
        </x-slot:footer>
    @endif
</x-auth.shell>
