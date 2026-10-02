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
        <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <x-application.settings-section title="{{ __('Owner modules') }}"
                description="{{ __('These modules stay on the instance. Each Odoo start mounts them read-only and links them into the addon folder. They are not copied into the client repository. Redeploy after a change.') }}">
                <form wire:submit="addModule" class="flex max-w-xl items-end gap-2">
                    <div class="min-w-0 flex-1">
                        <x-forms.input id="moduleName" label="{{ __('Module name') }}"
                            helper="{{ __('Folder name inside /data/coolify/gpsh-owner-modules on the instance server.') }}"
                            placeholder="sale_owner" />
                    </div>
                    <x-forms.button type="submit">{{ __('Add module') }}</x-forms.button>
                </form>
                <ul class="max-w-xl divide-y divide-neutral-200 dark:divide-white/10">
                    @forelse ($ownerModules as $module)
                        <li class="flex items-center justify-between gap-3 py-2 text-sm" wire:key="owner-module-{{ $module }}">
                            <span class="font-mono">{{ $module }}</span>
                            <button type="button" class="text-red-500" wire:click="removeModule('{{ $module }}')">{{ __('Remove') }}</button>
                        </li>
                    @empty
                        <li class="py-2 text-sm text-neutral-500">{{ __('No owner modules yet.') }}</li>
                    @endforelse
                </ul>
            </x-application.settings-section>
        </div>
    </x-settings.layout>
</div>
