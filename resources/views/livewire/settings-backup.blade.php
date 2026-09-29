<div>
    <x-slot:title>
        {{ __('Instance Backup | Coolify') }}
    </x-slot>

    <x-settings.layout>
    <div class="application-settings-form mx-auto flex w-full max-w-none min-w-0 flex-col gap-6">
        @if ($server->isFunctional())
            @if (isset($database) && isset($backup))
                <form wire:submit="submit">
                    <x-unsaved-bar action="submit" />

                    <x-application.settings-section title="{{ __('Instance database') }}">
                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-forms.input label="{{ __('Name') }}" readonly id="name" />
                            <x-forms.input label="{{ __('Description') }}" id="description" />
                            <div class="lg:col-span-2">
                                <x-forms.input label="{{ __('UUID') }}" readonly id="uuid" />
                            </div>
                            <x-forms.input label="{{ __('User') }}" readonly id="postgres_user" />
                            <x-forms.input type="password" label="{{ __('Password') }}" readonly id="postgres_password" />
                        </div>
                    </x-application.settings-section>
                </form>

                <livewire:project.database.backup-edit :backup="$backup" :available-s3-storages="$s3s"
                    :status="data_get($database, 'status')" />

                <livewire:project.database.backup-executions :backup="$backup" />
            @else
                <x-application.settings-section title="{{ __('Instance backup') }}">
                    <x-empty title="{{ __('Backup is not configured') }}"
                        description="{{ __('Coolify needs an internal database resource to create automatic backups.') }}"
                        icon-name="database" size="sm">
                        <x-slot:actions>
                            <x-forms.button wire:click="addCoolifyDatabase" isHighlighted>
                                {{ __('Configure backup') }}
                            </x-forms.button>
                        </x-slot:actions>
                    </x-empty>
                </x-application.settings-section>
            @endif
        @else
            <x-application.settings-section title="{{ __('Instance backup') }}">
                <x-callout type="danger" title="{{ __('Localhost is not ready') }}">
                    {{ __('Validate the localhost connection before configuring instance backups.') }}
                    <a href="{{ route('server.show', [$server->uuid]) }}" class="font-medium underline"
                        {{ wireNavigate() }}>{{ __('Open server settings') }}</a>
                </x-callout>
            </x-application.settings-section>
        @endif
    </div>
    </x-settings.layout>
</div>
