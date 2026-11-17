{{-- Railway-style launch preview for the selected catalog template. --}}
@php
    $selectedService = $selectedService ?? null;
    $serviceOptions = $serviceOptions ?? [];
    $preview = collect($serviceOptions)->firstWhere('value', $selectedService) ?? null;
@endphp

@if (is_array($preview))
    <div class="gc-launch-preview" wire:key="launch-preview-{{ $preview['value'] }}">
        <div class="gc-launch-preview-head">
            <div class="gc-launch-preview-logo">
                @if (filled($preview['logo'] ?? null))
                    <img src="{{ $preview['logo'] }}" alt="" class="size-full object-contain p-2"
                        onerror="this.src='{{ asset('svgs/default.webp') }}'">
                @else
                    <span class="text-sm font-semibold text-neutral-400">{{ strtoupper(substr((string) $preview['label'], 0, 1)) }}</span>
                @endif
            </div>
            <div class="min-w-0 flex-1">
                <p class="truncate text-[13px] font-semibold text-black dark:text-fg">{{ $preview['label'] }}</p>
                @if (filled($preview['category'] ?? null))
                    <p class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $preview['category'] }}</p>
                @endif
                @if (filled($preview['description'] ?? null))
                    <p class="mt-1 text-[12px] leading-5 text-neutral-600 dark:text-fg-dim">{{ $preview['description'] }}</p>
                @endif
            </div>
        </div>

        @if (($preview['services'] ?? []) !== [])
            <div class="gc-launch-preview-section">
                <p class="gc-launch-preview-kicker">{{ __('Will launch') }}</p>
                <ul class="gc-launch-preview-chips">
                    @foreach ($preview['services'] as $serviceName)
                        <li>{{ $serviceName }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (($preview['volumes'] ?? []) !== [])
            <div class="gc-launch-preview-section">
                <p class="gc-launch-preview-kicker">{{ __('Persistent volumes') }}</p>
                <ul class="gc-launch-preview-chips">
                    @foreach ($preview['volumes'] as $volumeName)
                        <li>{{ $volumeName }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (($preview['parameters'] ?? []) !== [])
            <div class="gc-launch-preview-section">
                <p class="gc-launch-preview-kicker">{{ __('Auto-generated parameters') }}</p>
                <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                    {{ __('Coolify fills these on create (passwords, URLs). You can review them in configuration after launch.') }}
                </p>
                <ul class="gc-launch-preview-params">
                    @foreach (array_slice($preview['parameters'], 0, 8) as $parameter)
                        <li><code>{{ $parameter }}</code></li>
                    @endforeach
                    @if (count($preview['parameters']) > 8)
                        <li class="text-neutral-500 dark:text-fg-dim">+{{ count($preview['parameters']) - 8 }}</li>
                    @endif
                </ul>
            </div>
        @endif

        @if (! empty($preview['needs_github']))
            <div class="gc-launch-preview-github">
                <img src="/svgs/github.svg" alt="" class="size-5 shrink-0 dark:invert" loading="lazy">
                <div class="min-w-0">
                    <p class="text-[13px] font-medium">{{ __('GitHub App (optional)') }}</p>
                    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                        {{ __('After Launch, connect the GitHub App to link a repository and branch for this environment’s addons.') }}
                    </p>
                </div>
            </div>
        @endif
    </div>
@endif
