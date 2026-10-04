<div>
    <x-slot:title>
        {{ __('Odoo templates | Coolify') }}
    </x-slot>

    <x-settings.layout>
        <form wire:submit="saveBaseDomain" class="application-settings-form mb-6 flex w-full min-w-0 flex-col gap-6">
            <x-application.settings-section title="{{ __('Odoo domain') }}"
                description="{{ __('Every project address uses this domain. Production is the name the client chooses, then this domain. A staging branch adds the branch and its id.') }}">
                <div class="max-w-xl">
                    <x-forms.input id="odooBaseDomain" label="{{ __('Domain') }}" placeholder="dev.odoo.com" />
                </div>
                <div>
                    <x-forms.button type="submit" isHighlighted>{{ __('Save domain') }}</x-forms.button>
                </div>
            </x-application.settings-section>
        </form>
        @if (isInstanceOwner())
            <form wire:submit="saveMailLimit" class="application-settings-form mb-6 flex w-full min-w-0 flex-col gap-6">
                <x-application.settings-section title="{{ __('Emails per day') }}"
                    description="{{ __('Each client team can send this many emails per day. A neutralized database does not send mail.') }}">
                    <div class="max-w-xs">
                        <x-forms.input id="mailDailyLimit" type="number" min="0" max="10000" label="{{ __('Emails per team') }}" />
                    </div>
                    <div>
                        <x-forms.button type="submit" isHighlighted>{{ __('Save limit') }}</x-forms.button>
                    </div>
                </x-application.settings-section>
            </form>
        @endif
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
                description="{{ __('House modules come from a GitHub branch on this instance. Each Odoo start copies them into the image addons, so they show up in the Odoo apps and stay out of the client addon folder and Jupyter. Saving does not restart Odoo. To put a package branch into a client project volume, open that service as owner and use Owner package.') }}">
                <form wire:submit="saveOwnerRepository" class="flex max-w-xl flex-col gap-4">
                    <x-forms.input id="ownerRepository" label="{{ __('Repository') }}"
                        helper="{{ __('GitHub repository, as owner/name. Each module is a folder with a manifest.') }}"
                        placeholder="owner/house-addons" />
                    <div class="flex items-end gap-2">
                        <div class="min-w-0 flex-1">
                            @if ($ownerBranches !== [])
                                <label class="mb-1.5 block text-sm font-medium" for="owner-branch">{{ __('Current branch') }}</label>
                                <select id="owner-branch" wire:model="ownerBranch" class="input">
                                    @foreach ($ownerBranches as $branch)
                                        <option value="{{ $branch }}">{{ $branch }}</option>
                                    @endforeach
                                </select>
                            @else
                                <x-forms.input id="ownerBranch" label="{{ __('Current branch') }}" placeholder="main" />
                            @endif
                        </div>
                        <x-forms.button type="button" wire:click="loadOwnerBranches">{{ __('Load branches') }}</x-forms.button>
                    </div>
                    <div>
                        <x-forms.button type="submit" isHighlighted>{{ __('Save branch') }}</x-forms.button>
                    </div>
                </form>
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
            <x-application.settings-section title="{{ __('Leftover volumes') }}"
                description="{{ __('These volumes are not used by a current environment. Deleting one removes its files.') }}">
                <div class="mb-3 flex flex-wrap items-center gap-3">
                    <button type="button" class="text-sm" wire:click="selectAllVolumes">{{ __('Select all') }}</button>
                    <button type="button" class="text-sm text-red-500" wire:click="deleteSelectedVolumes"
                        wire:confirm="{{ __('Delete the selected volumes and their files?') }}"
                        @disabled($selectedVolumes === [])>{{ __('Delete selected') }}</button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-left text-sm">
                        <thead class="text-neutral-500">
                            <tr>
                                <th class="w-8 py-2"></th>
                                <th class="py-2 pr-4">{{ __('Client') }}</th>
                                <th class="py-2 pr-4">{{ __('Instance') }}</th>
                                <th class="py-2">{{ __('Volume') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-white/10">
                            @forelse ($volumes['rows'] as $volume)
                                <tr wire:key="leftover-volume-{{ $volume['name'] }}">
                                    <td class="py-2">
                                        <input type="checkbox" value="{{ $volume['name'] }}" wire:model.live="selectedVolumes">
                                    </td>
                                    <td class="py-2 pr-4">{{ $volume['client'] !== '' ? __($volume['client']) : '—' }}</td>
                                    <td class="py-2 pr-4">{{ $volume['environment'] !== '' ? $volume['environment'] : '—' }}</td>
                                    <td class="py-2 font-mono">{{ $volume['name'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="py-2 text-neutral-500">{{ __('No leftover volumes.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($volumes['pages'] > 1)
                    <div class="mt-3 flex items-center justify-between gap-3 text-sm">
                        <button type="button" wire:click="previousVolumePage" @disabled($volumes['page'] <= 1)>{{ __('Previous') }}</button>
                        <span>{{ __('Page :current of :last', ['current' => $volumes['page'], 'last' => $volumes['pages']]) }}</span>
                        <button type="button" wire:click="nextVolumePage" @disabled($volumes['page'] >= $volumes['pages'])>{{ __('Next') }}</button>
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    </x-settings.layout>
</div>
