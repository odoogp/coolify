@props([
    'variant' => 'menu',
])

@php
    $locales = [
        'es' => 'Español',
        'en' => 'English',
    ];
    $current = app()->getLocale();
@endphp

@if ($variant === 'compact')
    <form method="POST" action="{{ route('locale.update') }}" class="auth-locale">
        @csrf
        <label class="sr-only" for="auth-locale">{{ __('Language') }}</label>
        <select id="auth-locale" name="locale" onchange="this.form.submit()">
            @foreach ($locales as $code => $label)
                <option value="{{ $code }}" @selected($current === $code)>{{ $label }}</option>
            @endforeach
        </select>
    </form>
@else
    <div class="px-2 pt-1 pb-0.5 text-[10px] font-medium tracking-wide text-neutral-400 uppercase dark:text-fg-faint">
        {{ __('Language') }}
    </div>
    @foreach ($locales as $code => $label)
        <form method="POST" action="{{ route('locale.update') }}">
            @csrf
            <input type="hidden" name="locale" value="{{ $code }}">
            <button type="submit"
                class="flex h-8 w-full items-center justify-between rounded-md px-2 text-left text-xs text-neutral-600 transition-colors hover:bg-neutral-200 hover:text-neutral-950 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
                <span>{{ $label }}</span>
                @if ($current === $code)
                    <svg class="size-3.5 text-coollabs dark:text-warning" viewBox="0 0 12 12" fill="none"
                        aria-hidden="true">
                        <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor" stroke-width="1.4"
                            stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                @endif
            </button>
        </form>
    @endforeach
@endif
