<div class="flex flex-col gap-6" x-init="$wire.loadImages">
    <form wire:submit="saveSettings" class="application-settings-form flex flex-col">
        <x-unsaved-bar action="saveSettings" />
        <x-application.settings-section id="rollback-retention-section" title="{{ __('Image retention') }}"
            helper="{{ __('Keep previously built Docker images available for fast rollbacks.') }}">
            @if ($serverRetentionDisabled)
                <div class="mb-4">
                    <x-callout type="warning" title="{{ __('Disabled by the server') }}">
                        {{ __('Image retention is disabled at the server level. This setting has no effect until a server') }}
                        administrator enables it.
                    </x-callout>
                </div>
            @endif

            <div class="max-w-sm">
                <x-forms.input id="dockerImagesToKeep" type="number" min="0" max="100"
                    label="{{ __('Images to keep') }}"
                    helper="{{ __('Set to 0 to keep only the running image. Pull request images are always removed during cleanup.') }}"
                    canGate="update" :canResource="$application" :disabled="$serverRetentionDisabled" />
            </div>
        </x-application.settings-section>
    </form>

    <x-application.settings-section id="rollback-images-section" title="{{ __('Available images') }}"
        helper="{{ __('Rollback uses an existing local image without rebuilding the application.') }}" flush>
        <x-slot:actions>
            @can('view', $application)
                <x-forms.button wire:click="loadImages(true)">
                    {{ __('Reload images') }}
                </x-forms.button>
            @endcan
        </x-slot:actions>

        <div wire:target="loadImages" wire:loading.remove>
            @forelse ($images as $image)
                @php
                    $tag = data_get($image, 'tag');
                    $date = data_get($image, 'created_at');
                    $createdAt = \Illuminate\Support\Carbon::parse($date);
                    $isCommitSha = preg_match('/^[0-9a-f]{7,128}$/i', $tag);
                    $isPrTag = preg_match('/^pr-\d+$/', $tag);
                    $isRollbackable = $isCommitSha || $isPrTag;
                    $isCurrent = data_get($image, 'is_current');
                @endphp
                <div
                    class="flex flex-col gap-3 border-b border-neutral-200 px-4 py-3.5 last:border-b-0 sm:flex-row sm:items-center dark:border-white/[0.07]">
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 ring-1 ring-neutral-200 dark:bg-white/[0.05] dark:text-fg-dim dark:ring-white/[0.07]">
                        <x-reicon name="layers" class="size-[18px]" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <code class="truncate font-mono text-[13px] font-semibold text-black dark:text-fg">
                                {{ $tag }}
                            </code>
                            @if ($isCurrent)
                                <x-status-badge status="Running image" type="success" />
                            @elseif (!$isRollbackable)
                                <x-status-badge status="Rollback unavailable" type="neutral" />
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-neutral-500 dark:text-fg-dim">
                            Built {{ $createdAt->diffForHumans() }}
                            <span class="mx-1 text-neutral-300 dark:text-fg-faint">·</span>
                            {{ $date }}
                        </p>
                    </div>
                    @can('deploy', $application)
                        @if ($isCurrent)
                            <x-forms.button disabled tooltip="This image is currently running.">
                                {{ __('Rollback') }}
                            </x-forms.button>
                        @elseif (!$isRollbackable)
                            <x-forms.button disabled
                                tooltip="Only commit and pull-request image tags support rollback.">
                                {{ __('Rollback') }}
                            </x-forms.button>
                        @else
                            <x-forms.button wire:click="rollbackImage('{{ $tag }}')">
                                {{ __('Roll back to this image') }}
                            </x-forms.button>
                        @endif
                    @endcan
                </div>
            @empty
                <x-empty title="{{ __('No rollback images') }}"
                    description="{{ __('No previous application images are currently stored on this server.') }}"
                    icon-name="layers" />
            @endforelse
        </div>

        <div class="w-full" wire:target="loadImages" wire:loading>
            <div class="flex items-center justify-center gap-2 px-4 py-10 text-[13px] text-neutral-500 dark:text-fg-dim">
                <x-loading class="size-4" />
                {{ __('Loading available images…') }}
            </div>
        </div>
    </x-application.settings-section>
</div>
