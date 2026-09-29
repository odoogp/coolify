<div class="application-settings-form">
    @if ($unsupported)
        <x-application.settings-section title="{{ __('Restore database') }}"
            description="{{ __('Import a backup into this database.') }}">
            <x-empty title="{{ __('Restore is not supported') }}"
                description="{{ __('This database type does not currently support backup imports.') }}"
                icon-name="database" size="sm" />
        </x-application.settings-section>
    @elseif (str($resourceStatus)->startsWith('running'))
        <livewire:project.database.import-form wire:key="database-import-form-{{ $resourceUuid }}" />
    @else
        <x-application.settings-section title="{{ __('Restore database') }}"
            description="{{ __('Import a backup into this database.') }}">
            <x-empty title="{{ __('Start the database first') }}"
                description="{{ __('The database must be running before Coolify can restore a backup.') }}"
                icon-name="database" size="sm" />
        </x-application.settings-section>
    @endif
</div>
