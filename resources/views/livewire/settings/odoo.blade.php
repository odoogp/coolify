<div>
    <x-slot:title>
        {{ __('Odoo templates | Coolify') }}
    </x-slot>

    <x-settings.layout>
        <form wire:submit="save" class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <x-application.settings-section title="{{ __('Odoo templates') }}"
                description="{{ __('Each Odoo version has its own Compose file and the PostgreSQL version that goes with it.') }}">
                <div class="grid max-w-xl gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium" for="odoo-template-version">{{ __('Odoo version') }}</label>
                        <select id="odoo-template-version" wire:model.live="version" class="input">
                            @foreach ($versions as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-forms.input id="postgresVersion" label="{{ __('PostgreSQL') }}"
                        helper="{{ __('Image tag used by the postgresql service, for example 16-alpine.') }}"
                        placeholder="16-alpine" />
                </div>
                <div class="flex max-w-xl items-end gap-2">
                    <div class="min-w-0 flex-1">
                        <x-forms.input id="newVersion" label="{{ __('New version') }}"
                            helper="{{ __('Image tag for a version that is not in the list, for example 21.') }}"
                            placeholder="21" />
                    </div>
                    <x-forms.button type="button" wire:click="createVersion">{{ __('Create template') }}</x-forms.button>
                </div>
                <x-forms.textarea id="compose" label="{{ __('Compose') }}" rows="22" />
                <div>
                    <x-forms.button type="submit" isHighlighted>{{ __('Save') }}</x-forms.button>
                </div>
            </x-application.settings-section>
        </form>
    </x-settings.layout>
</div>
