<div>
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
                <div class="mb-1 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">{{ __('Backups') }}</h1>
                        <p class="mt-1 max-w-2xl text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                            {{ __('Backups for this branch follow the plan. Restore overwrites the database and filestore on :branch.', ['branch' => $environment->name]) }}
                        </p>
                        <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                            {{ __('Plan') }}:
                            {{ $planName ?? __('No plan') }}
                            ·
                            {{ __('Plan policy') }}: {{ $policyLabel }}
                        </p>
                    </div>
                    @if ($canCreate)
                        <x-forms.button type="button" wire:click="createBackup" isHighlighted>
                            {{ __('Create Backup') }}
                        </x-forms.button>
                    @endif
                </div>

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
                        <table class="w-full min-w-[40rem] text-left text-[13px]">
                            <thead class="border-b border-neutral-200 bg-neutral-50 text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:border-white/[0.08] dark:bg-white/[0.03] dark:text-fg-faint">
                                <tr>
                                    <th class="px-3 py-2">{{ __('Time (UTC)') }}</th>
                                    <th class="px-3 py-2">{{ __('Branch') }}</th>
                                    <th class="px-3 py-2">{{ __('Version') }}</th>
                                    <th class="px-3 py-2">{{ __('Comment') }}</th>
                                    <th class="px-3 py-2">{{ __('Status') }}</th>
                                    <th class="px-3 py-2"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($backups as $row)
                                    <tr class="border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]" wire:key="odoo-backup-{{ $row['id'] }}">
                                        <td class="px-3 py-2 font-mono text-[12px]">{{ $row['time'] }}</td>
                                        <td class="px-3 py-2">{{ $row['branch'] }}</td>
                                        <td class="px-3 py-2">{{ $row['version'] }}</td>
                                        <td class="px-3 py-2">
                                            <span class="rounded bg-neutral-100 px-1.5 py-0.5 text-[12px] dark:bg-white/[0.06]">{{ $row['kind'] }}</span>
                                        </td>
                                        <td class="px-3 py-2">{{ $row['status'] }}</td>
                                        <td class="px-3 py-2 text-right">
                                            @if ($canRestore && $row['complete'])
                                                <button type="button" class="button"
                                                    wire:click="restore({{ $row['id'] }})"
                                                    wire:confirm="{{ __('Restore this backup onto :branch? Database and filestore will be overwritten.', ['branch' => $environment->name]) }}">
                                                    {{ __('Restore') }}
                                                </button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </section>
</div>
