@props([
    'title',
    'description' => null,
    'size' => 'base', // sm | base | lg
    'iconName' => null, // reicon name; use with icon-name="…"
])

@php
    $minHeight = match ($size) {
        'sm' => 'min-h-44',
        'lg' => 'min-h-96',
        default => 'min-h-80',
    };

    $iconBox = match ($size) {
        'sm' => 'mb-3 size-10',
        'lg' => 'mb-4 size-12',
        default => 'mb-4 size-11',
    };

    $iconSize = match ($size) {
        'sm' => 'size-4.5',
        'lg' => 'size-6',
        default => 'size-5',
    };

    $titleClass = match ($size) {
        'sm' => 'text-[14px] font-semibold text-black dark:text-fg',
        'lg' => 'text-base font-semibold text-black dark:text-fg',
        default => 'text-[15px] font-semibold text-black dark:text-fg',
    };

    $descriptionClass = match ($size) {
        'sm' => 'mt-1 max-w-sm text-[12px] leading-5 text-neutral-500 dark:text-fg-dim',
        default => 'mt-1 max-w-sm text-[13px] leading-5 text-neutral-500 dark:text-fg-dim',
    };

    $hasIconSlot = isset($icon) && $icon instanceof \Illuminate\View\ComponentSlot && ! $icon->isEmpty();
    $hasIconName = filled($iconName);
    $hasIcon = $hasIconSlot || $hasIconName;
    $artId = 'letify-art-'.substr(uniqid(), -8);

    // Prefer contents; fall back to actions (legacy slot name used in several views).
    $footer = null;
    if (isset($contents) && $contents instanceof \Illuminate\View\ComponentSlot && ! $contents->isEmpty()) {
        $footer = $contents;
    } elseif (isset($actions) && $actions instanceof \Illuminate\View\ComponentSlot && ! $actions->isEmpty()) {
        $footer = $actions;
    }
@endphp

{{-- Empty state: dashed card, icon badge, title, description, optional actions. --}}
<div
    {{ $attributes->merge([
        'class' => "empty-state flex w-full flex-col items-center justify-center rounded-xl border border-dashed border-neutral-300 px-6 py-10 text-center dark:border-white/[0.1] {$minHeight}",
    ]) }}>
    <div class="letify-empty-art" aria-hidden="true">
        <svg viewBox="0 0 280 180" class="h-36 w-auto" fill="none">
            <ellipse cx="140" cy="156" rx="78" ry="10" fill="#7B3FF2" opacity="0.12" />
            <rect x="78" y="48" width="124" height="88" rx="28" fill="url(#{{ $artId }}-card)" />
            <rect x="96" y="68" width="52" height="34" rx="12" fill="url(#{{ $artId }}-orange)" />
            <circle cx="168" cy="86" r="16" fill="#F6F2FD" />
            <path d="M160 86h16M168 78v16" stroke="#7B3FF2" stroke-width="2.4" stroke-linecap="round" />
            <rect x="96" y="110" width="88" height="8" rx="4" fill="#F6F2FD" />
            <circle cx="196" cy="58" r="18" fill="url(#{{ $artId }}-purple)" />
            <circle cx="64" cy="96" r="14" fill="#FFC542" />
            <defs>
                <linearGradient id="{{ $artId }}-card" x1="78" y1="48" x2="202" y2="136" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#FFFFFF" />
                    <stop offset="1" stop-color="#F6F2FD" />
                </linearGradient>
                <linearGradient id="{{ $artId }}-orange" x1="96" y1="68" x2="148" y2="102" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#FF6B3D" />
                    <stop offset="1" stop-color="#FFC542" />
                </linearGradient>
                <linearGradient id="{{ $artId }}-purple" x1="178" y1="40" x2="214" y2="76" gradientUnits="userSpaceOnUse">
                    <stop stop-color="#7B3FF2" />
                    <stop offset="1" stop-color="#D946EF" />
                </linearGradient>
            </defs>
        </svg>
    </div>

    @if ($hasIcon)
        <div
            class="empty-icon {{ $iconBox }} flex items-center justify-center rounded-xl border border-neutral-200 bg-white text-neutral-400 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-faint">
            @if ($hasIconSlot)
                {{ $icon }}
            @else
                <x-reicon :name="$iconName" class="{{ $iconSize }}" />
            @endif
        </div>
    @endif

    <h2 class="{{ $titleClass }}">{{ $title }}</h2>

    @if ($description)
        <p class="{{ $descriptionClass }}">{{ $description }}</p>
    @endif

    @if ($footer)
        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">
            {{ $footer }}
        </div>
    @endif
</div>
