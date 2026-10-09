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
                    helper="{{ __('Each backup is an Odoo zip (database dump and filestore). Restore overwrites :branch.', ['branch' => $environment->name]) }}">
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
                        <div
                            class="data-table overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]">
                            <div class="data-table-header odoo-backups-table-grid text-[11px]! uppercase tracking-wide">
                                <span>{{ __('Time (UTC)') }}</span>
                                <span class="odoo-backup-branch">{{ __('Branch') }}</span>
                                <span class="odoo-backup-version">{{ __('Version') }}</span>
                                <span class="odoo-backup-comment">{{ __('Comment') }}</span>
                                <span class="odoo-backup-status">{{ __('Status') }}</span>
                                <span class="odoo-backup-actions-header text-right">{{ __('Actions') }}</span>
                            </div>
                            @foreach ($backups as $row)
                                <div class="data-table-row odoo-backups-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.06]"
                                    wire:key="odoo-backup-{{ $row['id'] }}">
                                    <div class="min-w-0">
                                        <span
                                            class="block truncate font-mono text-[12px] font-semibold tabular-nums text-black dark:text-fg">
                                            {{ $row['time'] }}
                                        </span>
                                        <p class="odoo-backup-mobile-meta mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-dim">
                                            {{ collect([$row['branch'], $row['version'] !== '' && $row['version'] !== '-' ? $row['version'] : null, $row['kind']])->filter()->implode(' · ') }}
                                        </p>
                                    </div>
                                    <div class="odoo-backup-branch min-w-0 truncate text-[12px] text-neutral-700 dark:text-fg-dim">
                                        {{ $row['branch'] }}
                                    </div>
                                    <div class="odoo-backup-version tabular-nums text-[12px] text-neutral-700 dark:text-fg-dim">
                                        {{ $row['version'] }}
                                    </div>
                                    <div class="odoo-backup-comment min-w-0">
                                        <span
                                            @class([
                                                'inline-flex h-6 max-w-full items-center truncate rounded-full border px-2 text-[12px] font-medium',
                                                'border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-400/20 dark:bg-sky-400/10 dark:text-sky-200' => $row['automatic'],
                                                'border-neutral-200 bg-neutral-100 text-neutral-700 dark:border-white/[0.12] dark:bg-white/[0.07] dark:text-fg' => ! $row['automatic'],
                                            ])>
                                            {{ $row['kind'] }}
                                        </span>
                                    </div>
                                    <div class="odoo-backup-status">
                                        <x-status-badge :status="$row['status']" :type="$row['statusType']" />
                                    </div>
                                    <div class="odoo-backup-actions flex flex-nowrap items-center justify-end gap-1">
                                        @if ($canDownload && $row['downloadUrl'])
                                            <a class="button odoo-backup-action" href="{{ $row['downloadUrl'] }}" target="_blank"
                                                rel="noopener" title="{{ __('Download') }}" aria-label="{{ __('Download') }}">
                                                <x-reicon name="upload" class="size-3.5 rotate-180" />
                                                <span class="odoo-backup-action-label">{{ __('Download') }}</span>
                                            </a>
                                        @endif
                                        @if ($canRestore && $row['complete'])
                                            <button type="button" class="button odoo-backup-action"
                                                wire:click="restore({{ $row['id'] }})"
                                                wire:confirm="{{ __('Restore this backup onto :branch? Database and filestore will be overwritten.', ['branch' => $environment->name]) }}"
                                                title="{{ __('Restore') }}" aria-label="{{ __('Restore') }}">
                                                <x-reicon name="time-back" class="size-3.5" />
                                                <span class="odoo-backup-action-label">{{ __('Restore') }}</span>
                                            </button>
                                        @endif
                                        @if ($canDelete)
                                            <button type="button" class="button odoo-backup-action"
                                                wire:click="deleteBackup({{ $row['id'] }})"
                                                wire:confirm="{{ __('Delete this backup permanently?') }}"
                                                title="{{ __('Delete') }}" aria-label="{{ __('Delete') }}">
                                                <x-reicon name="trash" class="size-3.5" />
                                                <span class="odoo-backup-action-label">{{ __('Delete') }}</span>
                                            </button>
                                        @endif
                                        @if ($row['busy'])
                                            <span class="inline-flex items-center gap-1.5 text-[12px] text-neutral-500 dark:text-fg-dim">
                                                <span class="size-1.5 animate-pulse rounded-full bg-warning"></span>
                                                {{ __('Saving…') }}
                                            </span>
                                        @elseif (! $row['downloadUrl'] && ! $row['complete'] && ! $canDelete)
                                            <span class="text-[12px] text-neutral-400 dark:text-fg-faint">-</span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-application.settings-section>
            </div>
        </div>
    </section>
</div>
