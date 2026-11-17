@props([
    'title',
    'description' => null,
    'wide' => false,
    'scroll' => false,
    'tunnel' => null,
    'crystal' => false,
    'lockup' => false,
    'initialAuthState' => null,
])

@php
    $resolvedAuthState = $initialAuthState
        ?? (session('error') || (isset($errors) && $errors->any()) ? 'error' : 'idle');
    $isLogin = $tunnel === 'login';
    $uid = $tunnel ?: ($wide ? 'wide' : 'default');
@endphp

<section
    data-auth-state="{{ $resolvedAuthState }}"
    @class([
        'auth-shell application-settings-form',
        'auth-shell-wide' => $wide,
        'auth-shell-scroll' => $scroll && ! $wide,
        'auth-shell-tunnel' => filled($tunnel) && ! $isLogin,
        'auth-shell-split' => $tunnel === 'split',
        'auth-shell-terms' => $tunnel === 'full',
        'auth-shell-crystal' => $crystal && ! $isLogin,
        'auth-shell-login' => $isLogin,
        'auth-shell-mission' => $isLogin,
    ])
    x-data="{
        setAuthState(state) {
            const next = ['idle', 'email-focus', 'password-focus', 'submitting', 'error'].includes(state)
                ? state
                : 'idle';
            if ($el.dataset.authState === next) {
                return;
            }
            $el.dataset.authState = next;
            $el.dispatchEvent(new CustomEvent('auth-state', { detail: next, bubbles: true }));
        },
    }"
    x-on:auth-state="setAuthState($event.detail)">
    @if (filled($tunnel) && ! $isLogin)
        <x-flow-tunnel />
    @endif

    @if ($isLogin)
        <div class="auth-mission auth-mission--bleed">
            <div class="auth-mission-visual" aria-hidden="true">
                <div class="auth-stage auth-stage--space">
                    <x-auth.orbit :uid="$uid" />
                </div>
            </div>

            <p class="auth-mission-tagline" aria-hidden="true">{{ __('Artemis III · EVA') }}</p>

            {{-- Hero brand for the open burst; settles into the card wordmark. --}}
            <img
                class="auth-open-brand"
                src="{{ asset('brand/logotipo-oscuro.svg') }}"
                alt=""
                width="280"
                height="46"
                aria-hidden="true"
                decoding="async"
            >

            {{-- Dive veil: submit flies into stars, then app enter continues from the same black. --}}
            <div class="auth-dive-veil gc-star-veil" aria-hidden="true"></div>

            <div class="auth-shell-content">
                <div @class(['auth-card', 'auth-card-wide' => $wide])>
                    <div class="auth-card-heading">
                        <div class="auth-card-heading-row">
                            <div class="min-w-0">
                                <h1 class="auth-login-heading">
                                    <span class="sr-only">{{ $title }}</span>
                                    <img
                                        class="auth-login-brand"
                                        src="{{ asset('brand/logotipo-oscuro.svg') }}"
                                        alt="{{ $title }}"
                                        width="220"
                                        height="36"
                                    >
                                </h1>
                                @if ($description)
                                    <p class="auth-login-lede">{{ $description }}</p>
                                @endif
                            </div>
                            <div class="auth-mission-locale">
                                <x-locale-switcher variant="compact" />
                            </div>
                        </div>
                    </div>

                    <div class="auth-card-body">
                        {{ $slot }}
                    </div>

                    @isset($footer)
                        <footer class="auth-card-footer">
                            {{ $footer }}
                        </footer>
                    @endisset
                </div>
            </div>
        </div>
    @else
        <div class="auth-stage auth-stage--space" aria-hidden="true">
            <x-auth.orbit :uid="$uid" />
        </div>

        @isset($overlay)
            {{ $overlay }}
        @endisset

        <div class="auth-shell-content">
            <div @class(['auth-card', 'auth-card-wide' => $wide])>
                <div class="auth-card-heading">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            @if ($lockup)
                                <div class="auth-isologo auth-lockup-isologo">
                                    <img src="{{ product_logo_url() }}" alt="{{ $title }}">
                                </div>
                                @if ($description)
                                    <p>{{ $description }}</p>
                                @endif
                            @else
                                <h1>{{ $title }}</h1>
                                @if ($description)
                                    <p>{{ $description }}</p>
                                @endif
                            @endif
                        </div>
                        <x-locale-switcher variant="compact" />
                    </div>
                </div>

                <div class="auth-card-body">
                    {{ $slot }}
                </div>

                @isset($footer)
                    <footer class="auth-card-footer">
                        {{ $footer }}
                    </footer>
                @endisset
            </div>
        </div>
    @endif
</section>
