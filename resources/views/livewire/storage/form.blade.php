<form wire:submit="submit" class="application-settings-form">
    <x-unsaved-bar action="submit" />

    <x-application.settings-section title="{{ __('General') }}"
        description="{{ __('S3-compatible destination used by database and volume backups.') }}">
        <x-slot:actions>
            @can('validateConnection', $storage)
                <x-forms.button type="button" wire:click="testConnection">
                    <x-reicon name="check-circle" class="size-3.5" />
                    {{ __('Validate connection') }}
                </x-forms.button>
            @endcan
        </x-slot:actions>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input canGate="update" :canResource="$storage" label="{{ __('Name') }}" id="name" />
            <x-forms.input canGate="update" :canResource="$storage" label="{{ __('Description') }}" id="description" />
            <div class="lg:col-span-2">
                @can('update', $storage)
                    <x-forms.domain-input id="endpointParts" errorId="endpoint" host-label="{{ __('Host') }}"
                        host-placeholder="{{ __('minio.internal or 192.168.1.50') }}" />
                @else
                    <x-forms.input label="{{ __('Endpoint') }}" :value="$endpoint" disabled />
                @endcan
            </div>
            <x-forms.input canGate="update" :canResource="$storage" required label="{{ __('Bucket') }}" id="bucket" />
            <x-forms.input canGate="update" :canResource="$storage" required label="{{ __('Region') }}" id="region" />
            @if ($isPasswordHiddenForMember)
                <x-forms.input label="{{ __('Access key') }}" disabled value="Hidden (only admins can view)" />
                <x-forms.input label="{{ __('Secret key') }}" disabled value="Hidden (only admins can view)" />
            @else
                <x-forms.input canGate="update" :canResource="$storage" required type="password"
                    label="{{ __('Access key') }}" id="key" />
                <x-forms.input canGate="update" :canResource="$storage" required type="password"
                    label="{{ __('Secret key') }}" id="secret" />
            @endif
        </div>
    </x-application.settings-section>
</form>
