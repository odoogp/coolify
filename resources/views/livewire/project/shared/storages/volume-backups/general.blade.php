<form wire:submit="save" class="application-settings-form">
    <x-unsaved-bar action="save" />

    <x-application.settings-section title="{{ __('Backup schedule') }}"
        description="{{ __('Choose when this storage is archived and how long each backup may run.') }}">
        <x-slot:actions>
            <div class="flex items-center gap-2">
                @if (!$enabled)
                    <x-forms.button type="button" wire:click="toggleEnabled" wire:loading.attr="disabled"
                        wire:target="toggleEnabled" isHighlighted>{{ __('Enable backup') }}</x-forms.button>
                @else
                    <x-forms.button type="button" wire:click="toggleEnabled" wire:loading.attr="disabled"
                        wire:target="toggleEnabled">{{ __('Disable backup') }}</x-forms.button>
                @endif
                <x-forms.button type="button" wire:click="backupNow">{{ __('Back up now') }}</x-forms.button>
            </div>
        </x-slot:actions>

        <div class="mb-4 flex items-center justify-between gap-3 rounded-lg bg-neutral-50 px-3 py-2.5 ring-1 ring-neutral-200 dark:bg-white/[0.025] dark:ring-white/[0.07]">
            <span class="text-[12px] text-neutral-500 dark:text-fg-dim">
                {{ $backup?->targetType() ?? ($storage instanceof \App\Models\LocalFileVolume ? __('Directory') : __('Volume')) }}
            </span>
            <span class="truncate text-[12px] font-medium text-neutral-900 dark:text-fg">
                {{ $backup?->targetName() ?? ($storage instanceof \App\Models\LocalFileVolume ? $storage->fs_path : $storage->name) }}
            </span>
        </div>

        <x-callout type="warning" title="{{ __('File-level consistency') }}">
            {{ __('Archives created while the application writes to this storage can be inconsistent. Stopping containers') }}
            during the archive is safer, but briefly interrupts the application.
            <div class="mt-4">
                <x-forms.listbox id="stopDuringBackup" label="{{ __('Archive behavior') }}" live onChange="instantSave"
                    :options="[
                        ['value' => false, 'label' => __('Keep containers running')],
                        ['value' => true, 'label' => __('Stop containers during archive')],
                    ]" />
            </div>
        </x-callout>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <x-forms.input id="frequency" label="{{ __('Frequency') }}" required
                helper="{{ __('Use every_minute, hourly, daily, weekly, monthly, yearly, or a cron expression.') }}" />
            <x-forms.input id="timezone" label="{{ __('Timezone') }}" disabled
                helper="{{ __('Uses the backup server timezone, or the instance timezone when none is configured.') }}" required />
            <x-forms.input id="timeout" type="number" min="60" max="36000" label="{{ __('Timeout') }}"
                helper="{{ __('Maximum backup runtime in seconds.') }}" required />
        </div>
    </x-application.settings-section>
</form>
