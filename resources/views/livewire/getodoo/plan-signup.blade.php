<x-auth.shell scroll :title="product_name()"
    :description="__('Create the admin account for this package.')">
    <div class="flex flex-col gap-5">
        <div class="flex flex-col gap-2">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-neutral-500 dark:text-fg-dim">{{ __('Your plan') }}</p>
            <h2 class="text-xl font-semibold leading-7">{{ $plan->name }}</h2>
            <p class="text-2xl font-semibold leading-8">
                @if ($plan->isFree())
                    {{ __('Free') }}
                @else
                    ${{ number_format((float) $plan->price, 2) }}
                    <span class="ml-1 text-sm font-medium text-neutral-500 dark:text-fg-dim">{{ __('per month') }}</span>
                @endif
            </p>
            @if (filled($plan->summary))
                <p class="text-[13px] leading-5">{{ $plan->summary }}</p>
            @endif
            @if (filled($plan->description))
                <p class="whitespace-pre-line text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">{{ $plan->description }}</p>
            @endif
            <p class="text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">
                @if ($plan->isFree())
                    {{ __('This package has no charge.') }}
                @else
                    {{ __('This is the monthly price. This step pays the first charge with Wompi.') }}
                @endif
            </p>
            <ul class="mt-1 flex flex-col gap-1 border-t border-neutral-200 pt-3 text-[13px] leading-5 dark:border-white/10">
                @foreach ($plan->adminLimits() as $limit)
                    <li class="flex items-start justify-between gap-3">
                        <span class="text-neutral-500 dark:text-fg-dim">{{ $limit['label'] }}</span>
                        <span class="shrink-0 font-medium">{{ $limit['value'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="flex flex-col gap-4 border-t border-neutral-200 pt-4 dark:border-white/10">
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
