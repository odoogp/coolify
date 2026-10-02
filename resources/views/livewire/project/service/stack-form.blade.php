<form wire:submit.prevent="submit" class="application-settings-form flex flex-col gap-6">
    <x-unsaved-bar action="submit" />

    <x-application.settings-section title="{{ __('Service details') }}"
        description="{{ __('Manage the identity and Compose configuration for this service.') }}">
        <x-slot:actions>
            <div class="flex items-center gap-2">
                @if (isDev())
                    <x-status-badge label="Parser {{ $service->compose_parsing_version }}" type="neutral" />
                @endif
                @can('update', $service)
                    <x-modal-input buttonTitle="Edit Compose file" title="{{ __('Docker Compose') }}" :closeOutside="false"
                        :isLarge="true">
                        <x-slot:headerActions>
                            <div x-data="{ preview: false, validating: false, saving: false }"
                                @compose-validate-finished.window="validating = false"
                                @compose-save-finished.window="saving = false" class="flex items-center gap-2">
                                <x-forms.button
                                    @click="preview = !preview; $dispatch('compose-preview-toggle')">
                                    <x-reicon name="eye" class="size-3.5" />
                                    <span x-text="preview ? @js(__('Back to source Compose')) : @js(__('Preview generated Compose'))"></span>
                                </x-forms.button>
                                @if (blank($service->service_type))
                                    <x-forms.button @click="validating = true; $dispatch('compose-validate')"
                                        x-bind:disabled="validating">
                                        <x-loading-on-button x-show="validating" x-cloak />
                                        {{ __('Validate') }}
                                    </x-forms.button>
                                @endif
                                <x-forms.button @click="saving = true; $dispatch('compose-save')"
                                    x-bind:disabled="saving" isHighlighted>
                                    <x-loading-on-button x-show="saving" x-cloak />
                                    {{ __('Save changes') }}
                                </x-forms.button>
                            </div>
                        </x-slot:headerActions>
                        <livewire:project.service.edit-compose serviceId="{{ $service->id }}" />
                    </x-modal-input>
                @endcan
                <x-modal-input title="{{ __('Resource details') }}" buttonTitle="Details">
                    <livewire:project.shared.resource-details :resource="$service" />
                </x-modal-input>
            </div>
        </x-slot:actions>

        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input canGate="update" :canResource="$service" id="name" required label="{{ __('Service name') }}"
                placeholder="{{ __('My WordPress site') }}" />
            <x-forms.input canGate="update" :canResource="$service" id="description" label="{{ __('Description') }}" />
        </div>
    </x-application.settings-section>

    @if (isInstanceOwner())
    <x-application.settings-section title="{{ __('Network') }}"
        description="{{ __('Control whether this Compose stack joins Coolify\'s predefined network.') }}">
        <x-forms.listbox canGate="update" :canResource="$service" id="connectToDockerNetwork" label="{{ __('Network attachment') }}" live onChange="instantSave"
            :disabled="! auth()->user()->can('update', $service)" :options="[
                ['value' => false, 'label' => __('Use the stack network only')],
                ['value' => true, 'label' => __('Connect to the predefined Coolify network')],
            ]" />
    </x-application.settings-section>

    @if ($service->supportsOdooJupyter())
        <x-application.settings-section title="{{ __('Odoo') }}"
            description="{{ __('Pick the official Odoo image here. You do not need to edit the container or the Compose file.') }}">
            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ __('Choose the version, then redeploy. PostgreSQL stays as it is. A major upgrade does not migrate the database: take a backup first.') }}
            </p>
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.listbox canGate="update" :canResource="$service" id="odooVersion" label="{{ __('Odoo version') }}"
                    helper="{{ __('Uses the official odoo image. The running container changes only after you redeploy.') }}"
                    live :disabled="! auth()->user()->can('update', $service)" :options="collect(\App\Support\OdooVersion::SUPPORTED)->map(fn (string $version) => ['value' => $version, 'label' => 'Odoo '.$version])->all()" />
                <x-forms.listbox canGate="update" :canResource="$service" id="jupyterEnabled" label="{{ __('Enable JupyterLab') }}"
                    helper="{{ blank($service->environment?->project?->odooProfile?->git_repository) ? __('Without a repository, JupyterLab is the file manager. It opens the same addon folder Odoo uses.') : __('JupyterLab shares the addon volume of this Odoo instance. Redeploy after changing this. The token is stored as SERVICE_PASSWORD_JUPYTER. Upgrade the module in Odoo after editing addons.') }}"
                    live onChange="instantSave" :disabled="! auth()->user()->can('update', $service)" :options="[
                        ['value' => false, 'label' => __('Disabled')],
                        ['value' => true, 'label' => __('Enabled')],
                    ]" />
            </div>
            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ __('Addon files are one folder. Odoo reads /mnt/extra-addons. JupyterLab opens the same folder at /workspace/addons.') }}
            </p>
            @if ($image = \App\Support\OdooVersion::image((string) $service->docker_compose_raw))
                <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                    {{ __('Image that will be deployed') }}: <span class="font-mono text-neutral-800 dark:text-white">{{ $image }}</span>
                </p>
            @endif
        </x-application.settings-section>
    @endif
    @endif

    @if ($fields->count() > 0)
        <x-application.settings-section title="{{ __('Service configuration') }}"
            description="{{ __('Template-specific values exposed by this service.') }}">
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($fields as $serviceName => $field)
                    <div>
                        <div class="mb-1.5 flex items-center gap-1.5 text-[12px] font-medium">
                            <span>
                                @if (filled(data_get($field, 'serviceName')))
                                    {{ data_get($field, 'serviceName') }} ·
                                @endif
                                {{ data_get($field, 'name') }}
                            </span>
                            @if (data_get($field, 'customHelper'))
                                <x-helper helper="{{ data_get($field, 'customHelper') }}" />
                            @else
                                <x-helper helper="Variable name: {{ $serviceName }}" />
                            @endif
                        </div>
                        @if ($isPasswordHiddenForMember && data_get($field, 'isPassword'))
                            <x-forms.input disabled value="Hidden (only admins can view)" />
                        @else
                            <x-forms.input canGate="update" :canResource="$service"
                                type="{{ data_get($field, 'isPassword') ? 'password' : 'text' }}"
                                required="{{ str(data_get($field, 'rules'))?->contains('required') }}"
                                id="fields.{{ $serviceName }}.value" />
                        @endif
                    </div>
                @endforeach
            </div>
        </x-application.settings-section>
    @endif
</form>
