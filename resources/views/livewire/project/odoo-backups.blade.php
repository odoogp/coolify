<div @if ($hasBusy) wire:poll.5s @endif>
    <x-slot:title>
        {{ __('Backups') }} | {{ data_get_str($project, 'name')->limit(10) }} | {{ product_name() }}
    </x-slot>

    @if ($service)
        <livewire:project.service.heading :service="$service" :parameters="[
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'service_uuid' => $service->uuid,
        ]" :query="request()->query()" wire:key="service-heading-odoo-backups" />
    @endif

    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
            @if ($service)
                <x-service.configuration-sidebar :service="$service" current-route="project.odoo.backups" />
            @endif

            <div class="application-settings-form min-w-0 flex flex-col gap-6">
                <x-application.settings-section title="{{ __('Backups') }}"
                    helper="{{ __('Restore overwrites the database and filestore on :branch.', ['branch' => $environment->name]) }}">
                    @if ($canCreate)
                        <x-slot:actions>
                            <x-forms.button type="button" wire:click="createBackup" isHighlighted wire:loading.attr="disabled">
                                <x-reicon name="plus" class="size-3.5" />
                                {{ __('Create Backup') }}
                            </x-forms.button>
                        </x-slot:actions>
                    @endif

                    @if ($backupsBlockedByPlan)
                        <x-empty size="sm" title="{{ __('Want automatic backups?') }}"
                            description="{{ __('Contact an advisor to add backups to your plan and protect this project.') }}"
                            icon-name="database" />
                    @elseif ($backups === [])
                        <x-empty size="sm" title="{{ __('No backups yet') }}"
                            description="{{ $planAllowsAutomatic ? __('Automatic backups appear here when the plan schedule runs. You can also create one now.') : __('This plan has no automatic backups. You can create a manual backup.') }}"
                            icon-name="database" />
                    @else
                        <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-white/[0.08]">
                            <table class="w-full min-w-[44rem] text-left text-[13px]">
                                <thead
                                    class="border-b border-neutral-200 bg-neutral-50 text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:border-white/[0.08] dark:bg-white/[0.03] dark:text-fg-faint">
                                    <tr>
                                        <th class="px-3 py-2.5">{{ __('Time (UTC)') }}</th>
                                        <th class="px-3 py-2.5">{{ __('Branch') }}</th>
                                        <th class="px-3 py-2.5">{{ __('Version') }}</th>
                                        <th class="px-3 py-2.5">{{ __('Comment') }}</th>
                                        <th class="px-3 py-2.5">{{ __('Status') }}</th>
                                        <th class="px-3 py-2.5 text-right">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($backups as $row)
                                        <tr class="border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]"
                                            wire:key="odoo-backup-{{ $row['id'] }}">
                                            <td class="px-3 py-3 font-mono text-[12px] tabular-nums text-neutral-800 dark:text-fg">
                                                {{ $row['time'] }}
                                            </td>
                                            <td class="px-3 py-3">{{ $row['branch'] }}</td>
                                            <td class="px-3 py-3 tabular-nums">{{ $row['version'] }}</td>
                                            <td class="px-3 py-3">
                                                <span
                                                    @class([
                                                        'inline-flex h-6 items-center rounded-full border px-2 text-[12px] font-medium',
                                                        'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-400/20 dark:bg-sky-400/10 dark:text-sky-200' => $row['automatic'],
                                                        'border-neutral-200 bg-neutral-100 text-neutral-700 dark:border-white/[0.12] dark:bg-white/[0.07] dark:text-fg' => ! $row['automatic'],
                                                    ])>
                                                    {{ $row['kind'] }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-3">
                                                <x-status-badge :status="$row['status']" :type="$row['statusType']" />
                                            </td>
                                            <td class="px-3 py-3 text-right">
                                                <div class="inline-flex flex-wrap items-center justify-end gap-1">
                                                    @if ($canDownload && ($row['databaseDownloadUrl'] || $row['volumeDownloadUrl']))
                                                        @if ($row['databaseDownloadUrl'] && $row['volumeDownloadUrl'])
                                                            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                                                                <button type="button" class="button" @click="open = !open"
                                                                    :aria-expanded="open" title="{{ __('Download') }}">
                                                                    <x-reicon name="upload" class="size-3.5 rotate-180" />
                                                                    {{ __('Download') }}
                                                                    <x-reicon name="chevron-down" class="size-3 opacity-55" />
                                                                </button>
                                                                <div x-show="open" x-cloak x-transition.origin.top.right
                                                                    class="listbox-panel right-0! left-auto! z-[90]! w-48! min-w-48!">
                                                                    <a role="menuitem" class="listbox-option justify-start! gap-2!"
                                                                        href="{{ $row['databaseDownloadUrl'] }}" target="_blank" rel="noopener"
                                                                        @click="open = false">
                                                                        {{ __('Database dump') }}
                                                                    </a>
                                                                    <a role="menuitem" class="listbox-option justify-start! gap-2!"
                                                                        href="{{ $row['volumeDownloadUrl'] }}" target="_blank" rel="noopener"
                                                                        @click="open = false">
                                                                        {{ __('Filestore archive') }}
                                                                    </a>
                                                                </div>
                                                            </div>
                                                        @elseif ($row['databaseDownloadUrl'])
                                                            <a class="button" href="{{ $row['databaseDownloadUrl'] }}" target="_blank"
                                                                rel="noopener" title="{{ __('Download database dump') }}">
                                                                <x-reicon name="upload" class="size-3.5 rotate-180" />
                                                                {{ __('Download') }}
                                                            </a>
                                                        @else
                                                            <a class="button" href="{{ $row['volumeDownloadUrl'] }}" target="_blank"
                                                                rel="noopener" title="{{ __('Download filestore archive') }}">
                                                                <x-reicon name="upload" class="size-3.5 rotate-180" />
                                                                {{ __('Download') }}
                                                            </a>
                                                        @endif
                                                    @endif
                                                    @if ($canRestore && $row['complete'])
                                                        <button type="button" class="button"
                                                            wire:click="restore({{ $row['id'] }})"
                                                            wire:confirm="{{ __('Restore this backup onto :branch? Database and filestore will be overwritten.', ['branch' => $environment->name]) }}"
                                                            title="{{ __('Restore') }}">
                                                            <x-reicon name="time-back" class="size-3.5" />
                                                            {{ __('Restore') }}
                                                        </button>
                                                    @elseif ($row['busy'])
                                                        <span class="inline-flex items-center gap-1.5 text-[12px] text-neutral-500 dark:text-fg-dim">
                                                            <span class="size-1.5 animate-pulse rounded-full bg-warning"></span>
                                                            {{ __('Saving…') }}
                                                        </span>
                                                    @elseif (! $canDownload || (! $row['databaseDownloadUrl'] && ! $row['volumeDownloadUrl']))
                                                        <span class="text-[12px] text-neutral-400 dark:text-fg-faint">-</span>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </x-application.settings-section>
            </div>
        </div>
    </section>
</div>
