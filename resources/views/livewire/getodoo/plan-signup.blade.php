<x-auth.shell wide scroll :title="product_name()"
    :description="__('Create the admin account for this package.')">
    <div class="auth-plan-layout">
        <aside class="auth-plan-package">
            <p class="auth-plan-kicker">{{ __('Your plan') }}</p>
            <h2 class="auth-plan-name">{{ $plan->name }}</h2>
            <p class="auth-plan-price">
                @if ($plan->isFree())
                    {{ __('Free') }}
                @else
                    ${{ number_format((float) $plan->price, 2) }}
                    <span>{{ __('per month') }}</span>
                @endif
            </p>
            @if (filled($plan->summary))
                <p class="auth-plan-copy">{{ $plan->summary }}</p>
            @endif

            <div class="auth-plan-includes">
                <p class="auth-plan-kicker">{{ __('What is included') }}</p>
                @foreach (preg_split('/\R/u', (string) $plan->description) ?: [] as $line)
                    @if (filled(trim($line)))
                        <div class="auth-plan-feature">
                            <span class="auth-plan-mark" aria-hidden="true"></span>
                            <span>{{ trim($line) }}</span>
                        </div>
                    @endif
                @endforeach
                @foreach ($plan->adminLimits() as $limit)
                    <div class="auth-plan-include">
                        <span>{{ $limit['label'] }}</span>
                        <span @class([
                            'auth-plan-value',
                            'is-yes' => $limit['value'] === __('Yes'),
                            'is-no' => $limit['value'] === __('No'),
                        ])>{{ $limit['value'] }}</span>
                    </div>
                @endforeach
            </div>

            <p class="auth-plan-copy">
                @if ($plan->isFree())
                    {{ __('This package has no charge.') }}
                @else
                    {{ __('This is the monthly price. This step pays the first charge with Wompi.') }}
                @endif
            </p>
        </aside>

        <div class="flex flex-col gap-4">
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
                    <x-forms.input id="password" required type="password" autocomplete="new-password"
                        label="{{ __('input.password') }}" />
                    <x-forms.input id="password_confirmation" required type="password" autocomplete="new-password"
                        label="{{ __('input.password.again') }}" />

                    <div class="auth-guidance">
                        <x-reicon name="info-circle" class="mt-0.5 size-4 shrink-0" />
                        <p>{{ __('Use at least 8 characters with uppercase, lowercase, number, and symbol.') }}</p>
                    </div>

                    <x-forms.button class="w-full justify-center" type="submit" isHighlighted>
                        {{ $plan->isFree() ? __('Create account') : __('Continue to payment') }}
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
