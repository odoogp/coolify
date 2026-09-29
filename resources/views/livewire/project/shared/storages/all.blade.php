@php
    $gridClass = match (true) {
        $supportsPreviewSuffix => 'volumes-table-grid-with-pr',
        $showActionsColumn => 'volumes-table-grid',
        default => 'volumes-table-grid-readonly',
    };
@endphp

<div class="flex w-full flex-col">
    @if (data_get($resource, 'build_pack') === 'dockercompose')
        <div
            class="border-b border-neutral-200 px-4 py-3 text-[13px] leading-5 text-amber-800 dark:border-white/[0.08] dark:text-amber-300/90">
            {{ __('Docker Compose volume mounts are read-only here. Edit the compose file and reload it to change volumes.') }}
        </div>
    @endif

    @if ($resource->persistentStorages->isNotEmpty())
        <div class="data-table w-full">
            <div class="data-table-header {{ $gridClass }}">
                <span>{{ __('Volume Name') }}</span>
                <span class="volumes-col-source">{{ __('Source Path') }}</span>
                <span>{{ __('Destination Path') }}</span>
                @if ($supportsPreviewSuffix)
                    <span class="volumes-col-pr"
                        title="{{ __('Whether preview deployments receive an isolated -pr-N volume suffix.') }}">
                        {{ __('PR suffix') }}
                    </span>
                @endif
                <span class="volumes-col-backup text-center">{{ __('Backup') }}</span>
                @if ($showActionsColumn)
                    <span class="volumes-col-actions text-right">{{ __('Actions') }}</span>
                @endif
            </div>

            @foreach ($this->storages as $storage)
                @php
                    $id = $storage->id;
                    $form = $forms[$id] ?? null;
                    if (! $form) {
                        continue;
                    }
                    $backupMeta = $volumeBackupMeta[$id] ?? ['enabled' => false, 's3' => false, 'url' => null];
                    $hasEnabledBackup = $backupMeta['enabled'];
                    $hasS3Backup = $backupMeta['s3'];
                    $backupUrl = $backupMeta['url'];
                    $inputsReadonly = $form['isReadOnly'];
                    $displayHostPath = filled($form['hostPath']) ? $form['hostPath'] : '—';
                @endphp

                @if ($inputsReadonly)
                    <div class="env-table-item" wire:key="storage-row-{{ $id }}">
                        <div class="data-table-row {{ $gridClass }} text-[13px] text-neutral-700 dark:text-fg-dim">
                            <div class="volumes-cell-name min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">{{ __('Volume Name') }}</span>
                                <div class="flex min-w-0 items-center gap-2">
                                    <span
                                        class="min-w-0 truncate text-[13px] font-medium text-neutral-950 dark:text-fg"
                                        title="{{ $form['name'] }}">{{ $form['name'] }}</span>
                                </div>
                            </div>

                            <div class="volumes-col-source min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">{{ __('Source Path') }}</span>
                                <span class="block min-w-0 truncate text-[13px]"
                                    title="{{ $form['hostPath'] }}">{{ $displayHostPath }}</span>
                            </div>

                            <div class="volumes-cell-dest min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">{{ __('Destination Path') }}</span>
                                <span
                                    class="block min-w-0 truncate text-[13px] text-neutral-950 dark:text-fg"
                                    title="{{ $form['mountPath'] }}">{{ $form['mountPath'] }}</span>
                            </div>

                            @if ($supportsPreviewSuffix)
                                <div class="volumes-col-pr min-w-0">
                                    <span class="volumes-mobile-label volumes-field-label">{{ __('PR suffix') }}</span>
                                    <span>{{ $form['isPreviewSuffixEnabled'] ? __('Add suffix') : __('Share volume') }}</span>
                                </div>
                            @endif

                            <div class="volumes-col-backup flex items-center justify-center gap-1.5">
                                <span class="volumes-mobile-label volumes-field-label">{{ __('Backup') }}</span>
                                @if ($hasEnabledBackup)
                                    <a @if ($backupUrl) href="{{ $backupUrl }}" @endif title="{{ __('Volume backup is enabled') }}">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                            stroke-width="2" stroke="currentColor" class="size-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                        </svg>
                                    </a>
                                    <span @class(['table-badge', 'table-badge-success' => $hasS3Backup])
                                        title="{{ $hasS3Backup ? __('Backups are saved to S3') : __('Backups are stored locally only') }}">
                                        {{ $hasS3Backup ? __('S3') : __('Local') }}
                                    </span>
                                @else
                                    <span class="data-table-cell-dash">-</span>
                                @endif
                            </div>

                            @if ($showBackupAction)
                                <div
                                    class="volumes-col-actions volumes-cell-actions flex flex-wrap items-center justify-end gap-1.5">
                                    @if ($canUpdate)
                                        <x-modal-input title="{{ __('Configure Volume Backup') }}" :wireIgnore="false">
                                            <x-slot:content>
                                                <button type="button" class="icon-button" title="{{ __('Configure backup') }}"
                                                    aria-label="{{ __('Configure backup') }}">
                                                    <x-reicon name="database" class="size-4" />
                                                </button>
                                            </x-slot:content>
                                            @if ($resource instanceof \App\Models\Application)
                                                <livewire:project.application.backup.create :application="$resource"
                                                    :selected-target-key="'volume:' . $id"
                                                    wire:key="configure-volume-backup-{{ $id }}" />
                                            @else
                                                <livewire:project.service.volume-backup.create :service="$resource->service"
                                                    :selected-target-key="'volume:' . $id"
                                                    wire:key="configure-service-volume-backup-{{ $id }}" />
                                            @endif
                                        </x-modal-input>
                                    @else
                                        <span class="text-neutral-400 dark:text-fg-faint">—</span>
                                    @endif

                                    @if ($form['canDeleteStale'])
                                        <x-modal-confirmation title="{{ __('Remove stale volume entry?') }}" isErrorButton
                                            buttonTitle="Delete stale volume entry" submitAction="delete({{ $id }})"
                                            :checkboxes="[[
                                                'id' => 'deleteDockerVolume',
                                                'label' => __('Also permanently delete the Docker volume and all its data.'),
                                                'default_warning' => 'The Docker volume and its data will not be deleted.',
                                            ]]"
                                            :actions="[
                                                'This removes only the stale volume entry from Coolify.',
                                            ]" confirmationText="{{ $form['name'] }}"
                                            confirmationLabel="{{ __('Please confirm by entering the Storage Name below') }}"
                                            shortConfirmationLabel="{{ __('Storage Name') }}" />
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @else
                    <form wire:submit="submit({{ $id }})" class="env-table-item" wire:key="storage-row-{{ $id }}">
                        <div class="data-table-row {{ $gridClass }}">
                            <div class="volumes-cell-name min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">{{ __('Volume Name') }}</span>
                                <div class="flex min-w-0 items-center gap-2">
                                    <div class="min-w-0 flex-1">
                                        <x-forms.input id="forms.{{ $id }}.name" required />
                                    </div>
                                </div>
                            </div>

                            <div class="volumes-col-source min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">{{ __('Source Path') }}</span>
                                <x-forms.input id="forms.{{ $id }}.hostPath" placeholder="{{ __('Host path (optional)') }}" />
                            </div>

                            <div class="volumes-cell-dest min-w-0">
                                <span class="volumes-mobile-label volumes-field-label">{{ __('Destination Path') }}</span>
                                <x-forms.input id="forms.{{ $id }}.mountPath" required
                                    placeholder="/path/in/container" />
                            </div>

                            @if ($supportsPreviewSuffix)
                                <div class="volumes-col-pr min-w-0">
                                    <span class="volumes-mobile-label volumes-field-label">{{ __('PR suffix') }}</span>
                                    <x-forms.listbox id="forms.{{ $id }}.isPreviewSuffixEnabled" :options="[
                                        ['value' => true, 'label' => __('Add suffix')],
                                        ['value' => false, 'label' => __('Share volume')],
                                    ]" />
                                </div>
                            @endif

                            <div class="volumes-col-backup flex items-center justify-center gap-1.5">
                                <span class="volumes-mobile-label volumes-field-label">{{ __('Backup') }}</span>
                                @if ($hasEnabledBackup)
                                    <a @if ($backupUrl) href="{{ $backupUrl }}" @endif title="{{ __('Volume backup is enabled') }}">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                            stroke-width="2" stroke="currentColor" class="size-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                                        </svg>
                                    </a>
                                    <span @class(['table-badge', 'table-badge-success' => $hasS3Backup])
                                        title="{{ $hasS3Backup ? __('Backups are saved to S3') : __('Backups are stored locally only') }}">
                                        {{ $hasS3Backup ? __('S3') : __('Local') }}
                                    </span>
                                @else
                                    <span class="data-table-cell-dash">-</span>
                                @endif
                            </div>

                            <div
                                class="volumes-col-actions volumes-cell-actions flex flex-wrap items-center justify-end gap-1.5">
                                <x-forms.button type="submit" class="!px-2.5 !text-xs">
                                    {{ __('Update') }}
                                </x-forms.button>

                                @if ($showBackupAction)
                                    <x-modal-input title="{{ __('Configure Volume Backup') }}" :wireIgnore="false">
                                        <x-slot:content>
                                            <button type="button" class="icon-button" title="{{ __('Configure backup') }}"
                                                aria-label="{{ __('Configure backup') }}">
                                                <x-reicon name="database" class="size-4" />
                                            </button>
                                        </x-slot:content>
                                        @if ($resource instanceof \App\Models\Application)
                                            <livewire:project.application.backup.create :application="$resource"
                                                :selected-target-key="'volume:' . $id"
                                                wire:key="configure-volume-backup-{{ $id }}" />
                                        @else
                                            <livewire:project.service.volume-backup.create :service="$resource->service"
                                                :selected-target-key="'volume:' . $id"
                                                wire:key="configure-service-volume-backup-{{ $id }}" />
                                        @endif
                                    </x-modal-input>
                                @elseif (method_exists($resource, 'isBackupSolutionAvailable') && $resource->isBackupSolutionAvailable())
                                    <x-modal-input title="{{ __('New Scheduled Backup') }}" :wireIgnore="false">
                                        <x-slot:content>
                                            <button type="button" class="icon-button" title="{{ __('Configure backup') }}"
                                                aria-label="{{ __('Configure backup') }}">
                                                <x-reicon name="database" class="size-4" />
                                            </button>
                                        </x-slot:content>
                                        <livewire:project.database.create-scheduled-backup :database="$resource"
                                            wire:key="configure-database-backup-{{ $id }}" />
                                    </x-modal-input>
                                @endif

                                <x-modal-confirmation title="{{ __('Confirm persistent storage deletion?') }}" isErrorButton
                                    buttonTitle="Delete" submitAction="delete({{ $id }})" :actions="[
                                        'The selected persistent storage/volume will be permanently deleted.',
                                        'If the persistent storage/volume is actvily used by a resource data will be lost.',
                                    ]" confirmationText="{{ $form['name'] }}"
                                    confirmationLabel="{{ __('Please confirm the execution of the actions by entering the Storage Name below') }}"
                                    shortConfirmationLabel="{{ __('Storage Name') }}" />
                            </div>
                        </div>
                    </form>
                @endif
            @endforeach
        </div>
    @endif

</div>
