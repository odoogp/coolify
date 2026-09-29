<form wire:submit="save" class="application-settings-form">
    <x-unsaved-bar action="save" />

    <x-application.settings-section title="{{ __('Retention') }}"
        description="{{ __('The first reached limit removes the oldest archive. Use 0 for unlimited retention.') }}">
        <div>
            <h3 class="mb-3 text-sm font-semibold text-black dark:text-fg">{{ __('Local backups') }}</h3>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-forms.input label="{{ __('Backups to keep') }}" id="retentionAmountLocally" type="number"
                    min="0" canGate="update" :canResource="$resource"
                    helper="{{ __('Maximum number of recent local backups.') }}" required />
                <x-forms.input label="{{ __('Days to keep') }}" id="retentionDaysLocally" type="number"
                    min="0" canGate="update" :canResource="$resource"
                    helper="{{ __('Remove local backups older than this many days.') }}" required />
                <x-forms.input label="{{ __('Maximum storage (GB)') }}" id="retentionMaxStorageLocally" type="number"
                    min="0" step="any" canGate="update" :canResource="$resource"
                    helper="{{ __('Remove the oldest local backups after this total size is reached.') }}" required />
            </div>
        </div>

        <div class="mt-6 border-t border-neutral-200 pt-6 dark:border-white/[0.06]">
            <h3 class="mb-3 text-sm font-semibold text-black dark:text-fg">{{ __('S3 backups') }}</h3>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-forms.input label="{{ __('Backups to keep') }}" id="retentionAmountS3" type="number" min="0"
                    canGate="update" :canResource="$resource" helper="{{ __('Maximum number of recent S3 backups.') }}" required />
                <x-forms.input label="{{ __('Days to keep') }}" id="retentionDaysS3" type="number" min="0"
                    canGate="update" :canResource="$resource"
                    helper="{{ __('Remove S3 backups older than this many days.') }}" required />
                <x-forms.input label="{{ __('Maximum storage (GB)') }}" id="retentionMaxStorageS3" type="number"
                    min="0" step="any" canGate="update" :canResource="$resource"
                    helper="{{ __('Remove the oldest S3 backups after this total size is reached.') }}" required />
            </div>
        </div>
    </x-application.settings-section>
</form>
