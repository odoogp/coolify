@php
    [$statusType, $statusLabel] = match (true) {
        str($resource->status)->contains('running') => ['success', formatContainerStatus($resource->status)],
        str($resource->status)->contains(['starting', 'restarting', 'degraded']) => ['warning', formatContainerStatus($resource->status)],
        default => ['error', formatContainerStatus($resource->status)],
    };
    $resourceName = $resource->human_name
        ? Str::headline($resource->human_name)
        : Str::headline($resource->name);
@endphp

{{--
  Trigger + settings modal. viewMode lives on the parent Compose resources
  wrapper (CSS classes) — nested Alpine scopes here cannot see it.
--}}
<x-modal-input title="{{ $resourceName }}"
    subtitle="{{ $isApplication ? __('Identity, image, and public access for this compose application.') : __('Identity, image, and public access for this compose database.') }}"
    :contentClicks="false" :wireIgnore="false" isLarge class="compose-resource block w-full min-w-0">
    <x-slot:content>
        {{-- Grid card --}}
        <div class="compose-resource--grid group flex min-w-0 cursor-pointer flex-col overflow-hidden rounded-[10px] border border-neutral-200 bg-white transition-[border-color,background-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-surface dark:hover:border-white/[0.12] dark:hover:bg-white/[0.035]"
            role="button" tabindex="0" aria-label="{{ __('Open :name settings', ['name' => $resourceName]) }}"
            @click="modalOpen = true" @keydown.enter.prevent="modalOpen = true">
            <div class="flex min-w-0 flex-1 items-start gap-3 p-4">
                <div
                    class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                    <x-reicon :name="$isDatabase ? 'database' : 'grid'" class="size-4" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex min-w-0 items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="truncate text-sm font-semibold text-black dark:text-fg">{{ $resourceName }}</div>
                            <div class="mt-0.5 truncate font-mono text-xs text-neutral-500 dark:text-fg-faint">
                                {{ $resource->image }}
                            </div>
                        </div>
                        <x-status-badge :status="$statusLabel" :type="$statusType" />
                        <x-application.restart-limit-warning :application="$resource" />
                    </div>

                    @if ($resource->configuration_required)
                        <div class="mt-2">
                            <x-status-badge status="Configuration required" type="warning" />
                        </div>
                    @elseif ($resource->description)
                        <p class="mt-2 line-clamp-2 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                            {{ $resource->description }}
                        </p>
                    @endif
                </div>
            </div>

            <div
                class="flex items-center justify-end gap-1 border-t border-neutral-200 bg-neutral-50 px-3 py-2 dark:border-white/[0.06] dark:bg-white/[0.02]">
                @if ($isDatabase && ($resource->isBackupSolutionAvailable() || $resource->is_migrated))
                    <a class="icon-button" title="{{ __('Service backups') }}" aria-label="{{ __('Service backups') }}"
                        {{ wireNavigate() }} @click.stop
                        href="{{ route('project.service.volume-backups.index', $parameters) }}">
                        <x-reicon name="database" class="size-4" />
                    </a>
                @endif
                @if ($isApplication && $resource->fqdn)
                    @can('update', $service)
                        <a class="icon-button" title="{{ __('Manage domains') }}" aria-label="{{ __('Manage domains') }}"
                            {{ wireNavigate() }} @click.stop
                            href="{{ route('project.service.domains', $parameters) }}">
                            <x-reicon name="globe" class="size-4" />
                        </a>
                    @endcan
                @endif
                <button type="button" class="icon-button" title="{{ __('Resource settings') }}"
                    aria-label="{{ __('Resource settings') }}" @click.stop="modalOpen = true">
                    <x-reicon name="settings" class="size-4" />
                </button>
                @if (str($resource->status)->contains('running'))
                    @can('update', $service)
                        <div @click.stop>
                            <x-modal-confirmation
                                :title="$isApplication ? __('Confirm Service Application Restart?') : __('Confirm Service Database Restart?')"
                                :buttonTitle="__('Restart')" submitAction="restart" :actions="$isApplication
                                    ? [__('The selected service application will be unavailable during the restart.')]
                                    : [__('This service database will be unavailable during the restart.')]"
                                :confirmWithText="false" :confirmWithPassword="false"
                                :step2ButtonText="$isApplication ? __('Restart Service Container') : __('Restart Database')" />
                        </div>
                    @endcan
                @endif
            </div>
        </div>

        {{-- Table row — columns must match the Compose resources header --}}
        <div class="compose-resource--table grid min-h-14 min-w-0 cursor-pointer grid-cols-1 items-center gap-2 border-b border-neutral-200 px-4 py-3 last:border-b-0 hover:bg-neutral-50 focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-primary sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(8rem,auto)_auto] sm:gap-3 sm:py-2.5 dark:border-white/[0.07] dark:hover:bg-white/[0.025]"
            role="button" tabindex="0" aria-label="{{ __('Open :name settings', ['name' => $resourceName]) }}"
            @click="modalOpen = true" @keydown.enter.prevent="modalOpen = true">
            <div class="flex min-w-0 items-center gap-3">
                <div
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.06] dark:text-fg-dim">
                    <x-reicon :name="$isDatabase ? 'database' : 'grid'" class="size-4" />
                </div>
                <div class="min-w-0">
                    <div class="truncate text-[13px] font-semibold text-black dark:text-fg">{{ $resourceName }}</div>
                    <div class="mt-0.5 truncate font-mono text-xs text-neutral-500 sm:hidden dark:text-fg-faint">
                        {{ $resource->image }}
                    </div>
                </div>
            </div>
            <div class="hidden min-w-0 truncate font-mono text-xs text-neutral-500 sm:block dark:text-fg-faint">
                {{ $resource->image }}
            </div>
            <div class="flex min-w-0 flex-wrap items-center gap-1">
                <x-status-badge :status="$statusLabel" :type="$statusType" />
                <x-application.restart-limit-warning :application="$resource" />
            </div>
            <div class="flex items-center justify-end gap-1">
                @if ($isDatabase && ($resource->isBackupSolutionAvailable() || $resource->is_migrated))
                    <a class="icon-button" title="{{ __('Service backups') }}" aria-label="{{ __('Service backups') }}"
                        {{ wireNavigate() }} @click.stop
                        href="{{ route('project.service.volume-backups.index', $parameters) }}">
                        <x-reicon name="database" class="size-4" />
                    </a>
                @endif
                @if ($isApplication && $resource->fqdn)
                    @can('update', $service)
                        <a class="icon-button" title="{{ __('Manage domains') }}" aria-label="{{ __('Manage domains') }}"
                            {{ wireNavigate() }} @click.stop
                            href="{{ route('project.service.domains', $parameters) }}">
                            <x-reicon name="globe" class="size-4" />
                        </a>
                    @endcan
                @endif
                <button type="button" class="icon-button" title="{{ __('Resource settings') }}"
                    aria-label="{{ __('Resource settings') }}" @click.stop="modalOpen = true">
                    <x-reicon name="settings" class="size-4" />
                </button>
            </div>
        </div>
    </x-slot:content>

    @if ($isApplication)
        <livewire:project.service.index :serviceApplication="$resource" :embedded="true"
            wire:key="service-application-settings-{{ $resource->id }}" lazy />
    @else
        <livewire:project.service.index :serviceApplication="$resource" :embedded="true"
            wire:key="service-database-settings-{{ $resource->id }}" lazy />
    @endif
</x-modal-input>
