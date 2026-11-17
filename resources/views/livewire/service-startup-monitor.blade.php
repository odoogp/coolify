@php
    $serviceLabel = filled($service->name) ? $service->name : __('this service');
    $runningStage = collect($stages)->firstWhere('status', 'running');
@endphp

<div class="flex h-full min-h-0 flex-col gap-3 sm:gap-4"
    @if ($isPollingActive) wire:poll.1500ms="polling" @endif>
    <div
        class="shrink-0 rounded-[10px] border border-neutral-200 bg-neutral-50 px-4 py-3 text-[13px] leading-5 text-neutral-600 dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-dim">
        @if ($isOnline)
            {{ __(':name is ready. You can open it now.', ['name' => $serviceLabel]) }}
        @elseif ($failed)
            {{ __('We could not finish starting :name. Check the details below or try again.', ['name' => $serviceLabel]) }}
        @elseif ($runningStage)
            {{ __('Starting :name. :step', ['name' => $serviceLabel, 'step' => __($runningStage['description'])]) }}
        @else
            {{ __('Starting :name. This usually takes a minute.', ['name' => $serviceLabel]) }}
        @endif
    </div>

    <div data-startup-checkpoints
        class="min-h-0 shrink-0 overflow-hidden rounded-[10px] border border-neutral-200 dark:border-white/[0.08]">
        <div class="border-b border-neutral-200 px-4 py-2.5 dark:border-white/[0.08]">
            <h3 class="text-[13px] font-medium text-neutral-600 dark:text-fg-dim">{{ __('Launch progress') }}</h3>
        </div>
        <div class="max-h-[min(42dvh,22rem)] overflow-y-auto overscroll-contain scrollbar divide-y divide-neutral-200 dark:divide-white/[0.07]"
            x-data="{
                observer: null,
                scrollToRunning() {
                    this.$el.querySelector('[data-checkpoint-status=running]')?.scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest',
                    })
                }
            }"
            x-init="$nextTick(() => scrollToRunning()); observer = new MutationObserver(() => $nextTick(() => scrollToRunning())); observer.observe($el, { subtree: true, childList: true, attributes: true, attributeFilter: ['data-checkpoint-status'] })"
            x-destroy="observer?.disconnect()">
            @foreach ($stages as $stage)
                <x-checkpoint-item wire:key="startup-stage-{{ $stage['key'] }}"
                    :title="__($stage['title'])" :description="__($stage['description'])"
                    :status="$stage['status']" data-checkpoint-status="{{ $stage['status'] }}"
                    class="min-h-12! py-2.5!" />
            @endforeach
        </div>
    </div>

    @if ($isOnline)
        <div
            class="mt-auto flex shrink-0 flex-col gap-3 rounded-[10px] border border-emerald-500/20 bg-emerald-500/[0.06] px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex min-w-0 items-center gap-2 text-[13px] font-medium text-emerald-700 dark:text-emerald-300">
                <x-reicon name="check-circle" class="size-4 shrink-0" />
                <span class="min-w-0 truncate">{{ __('The service is online.') }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                @if (filled($openUrl))
                    <a class="button button-highlighted" target="_blank" rel="noopener noreferrer" href="{{ $openUrl }}">
                        <x-reicon name="external-link" class="size-3.5 opacity-70" />
                        {{ __('Open') }}
                    </a>
                @endif
                <x-forms.button type="button" @click="processDialogOpen = false">
                    {{ __('Close') }}
                </x-forms.button>
            </div>
        </div>
    @elseif ($failed)
        <div
            class="mt-auto shrink-0 rounded-[10px] border border-red-500/20 bg-red-500/[0.06] px-4 py-3 text-[13px] leading-5 text-red-700 dark:text-red-300">
            <div class="mb-1 flex items-center gap-2 text-[12px] font-semibold uppercase tracking-[0.06em]">
                <x-reicon name="alert-circle" class="size-3.5 shrink-0" />
                {{ __('Startup failed') }}
            </div>
            <p class="text-[12px] leading-5 text-red-700/90 dark:text-red-300/90">
                {{ __('Something went wrong while starting this service. Open the details below for the full log.') }}
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                <x-forms.button type="button" @click="processDialogOpen = false">
                    {{ __('Close') }}
                </x-forms.button>
            </div>
        </div>
    @endif

    <div class="shrink-0 border-t border-neutral-200 pt-3 dark:border-white/[0.08]">
        <button type="button" wire:click="toggleLogs"
            class="inline-flex h-8 items-center gap-1.5 rounded-md px-1 text-[12px] font-medium text-neutral-600 outline-none transition-colors hover:text-black focus-visible:ring-1 focus-visible:ring-accent dark:text-fg-dim dark:hover:text-fg">
            <x-reicon :name="$showLogs ? 'chevron-down' : 'chevron-right'" class="size-3.5 opacity-70" />
            {{ $showLogs ? __('Hide details') : __('Show details') }}
        </button>

        @if ($showLogs)
            <section class="application-settings-section validation-installation-logs mt-2 overflow-hidden">
                <div class="application-settings-section-body p-0!">
                    <div class="max-h-48 overflow-y-auto px-3 py-2.5 scrollbar sm:max-h-56">
                        @if (filled($this->logOutput))
                            <pre
                                class="font-logs min-w-0 max-w-full whitespace-pre-wrap wrap-anywhere text-[11px] leading-4 text-neutral-700 dark:text-fg-dim">{{ $this->logOutput }}</pre>
                        @else
                            <p class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ __('No log output yet.') }}</p>
                        @endif
                    </div>
                </div>
            </section>
        @endif
    </div>
</div>
