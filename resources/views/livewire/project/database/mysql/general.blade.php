<div class="application-settings-form">
    <form wire:submit="submit" class="flex flex-col gap-6">
        <x-unsaved-bar action="submit" />

        <x-application.settings-section title="{{ __('Database details') }}"
            description="{{ __('Manage the identity and container image for this MySQL database.') }}">
            <x-slot:actions>
                <x-modal-input title="{{ __('Resource details') }}" buttonTitle="Details">
                    <livewire:project.shared.resource-details :resource="$database" />
                </x-modal-input>
            </x-slot:actions>
            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input label="{{ __('Name') }}" id="name" canGate="update" :canResource="$database" />
                <x-forms.input label="{{ __('Description') }}" id="description" canGate="update" :canResource="$database" />
                <div class="lg:col-span-2">
                    <x-forms.input label="{{ __('Image') }}" id="image" required canGate="update" :canResource="$database"
                        helper="{{ __('Use a published MySQL image from Docker Hub.') }}" />
                </div>
            </div>
        </x-application.settings-section>

        <x-application.settings-section title="{{ __('Credentials') }}"
            description="{{ __('Keep these values aligned with the credentials configured inside MySQL.') }}">
            @if ($database->started_at)
                <x-callout type="warning" title="{{ __('Keep credentials synchronized') }}">
                    {{ __('Changing values here does not update MySQL. Update MySQL first, then synchronize the values here so') }}
                    backups and other automations continue working.
                </x-callout>
            @endif
            <div class="{{ $database->started_at ? 'mt-4 ' : '' }}grid gap-4 lg:grid-cols-2">
                @if ($isPasswordHiddenForMember)
                    <x-forms.input label="{{ __('Root password') }}" disabled value="Hidden (only admins can view)" />
                @else
                    <x-forms.input label="{{ __('Root password') }}" id="mysqlRootPassword" type="password"
                        :required="(bool) $database->started_at" canGate="update" :canResource="$database" />
                @endif
                <x-forms.input label="{{ __('Normal user') }}" id="mysqlUser" required canGate="update"
                    :canResource="$database" />
                @if ($isPasswordHiddenForMember)
                    <x-forms.input label="{{ __('Normal user password') }}" disabled value="Hidden (only admins can view)" />
                @else
                    <x-forms.input label="{{ __('Normal user password') }}" id="mysqlPassword" type="password" required
                        canGate="update" :canResource="$database" />
                @endif
                <x-forms.input label="{{ __('Initial database') }}" id="mysqlDatabase"
                    placeholder="{{ __('If empty, it will match the normal user.') }}"
                    :readonly="(bool) $database->started_at" canGate="update" :canResource="$database"
                    helper="{{ $database->started_at ? __('You can only change this in the database.') : null }}" />
            </div>
        </x-application.settings-section>

        <x-application.settings-section title="{{ __('Runtime and network') }}"
            description="{{ __('Configure Docker runtime options and host port mappings.') }}">
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="lg:col-span-2">
                    <x-forms.input
                        helper="{{ __('Add supported docker run options used when the container starts. Unsupported options can interfere with Coolify automation.') }}"
                        placeholder="--cap-add SYS_ADMIN --device=/dev/fuse"
                        id="customDockerRunOptions" label="{{ __('Custom Docker options') }}" canGate="update"
                        :canResource="$database" />
                </div>
                <x-forms.input placeholder="3000:3306" id="portsMappings" label="{{ __('Port mappings') }}"
                    helper="{{ __('Comma-separated host-to-container mappings, for example 3000:3306.') }}"
                    canGate="update" :canResource="$database" />
            </div>
            <div class="mt-4">
                <livewire:project.database.mysql.status-info :database="$database" />
            </div>
        </x-application.settings-section>

        <x-application.settings-section title="{{ __('Public access') }}" class="relative"
            description="{{ __('Expose this database through the managed TCP proxy.') }}">
            <x-slot:actions>
                @if ($isPublic)
                    <x-process-dialog closeWithX size="xl">
                        <x-slot:title>Proxy logs</x-slot:title>
                        <x-slot:content>
                            <livewire:project.shared.get-logs :server="$server" :resource="$database"
                                container="{{ data_get($database, 'uuid') }}-proxy" :collapsible="false" lazy />
                        </x-slot:content>
                        <x-forms.button @click="processDialogOpen = true">View logs</x-forms.button>
                    </x-process-dialog>
                @endif
            </x-slot:actions>
            <x-table.loading target="instantSave" text="Updating public access..." />
            <div class="grid gap-4 lg:grid-cols-2">
                <div wire:key="public-access-{{ $publicPort ?: 'unset' }}">
                    <x-forms.listbox id="isPublic" label="{{ __('Access') }}" live onChange="instantSave"
                        :disabled="! auth()->user()->can('update', $database)" canGate="update" :canResource="$database" :options="[
                            ['value' => false, 'label' => __('Private')],
                            ['value' => true, 'label' => blank($publicPort) ? 'Public through TCP proxy (set public port first)' : 'Public through TCP proxy', 'disabled' => blank($publicPort)],
                        ]" />
                </div>
                <x-forms.input type="number" placeholder="3306" disabled="{{ $isPublic }}" id="publicPort"
                    label="{{ __('Public port') }}" canGate="update" :canResource="$database" />
                <x-forms.input type="number" placeholder="3600" disabled="{{ $isPublic }}" id="publicPortTimeout"
                    label="{{ __('Proxy timeout') }}" helper="{{ __('Timeout in seconds. The default is 3600.') }}"
                    canGate="update" :canResource="$database" />
            </div>
        </x-application.settings-section>

        <x-application.settings-section title="{{ __('Configuration') }}"
            description="{{ __('Override the MySQL configuration used by this container.') }}">
            <x-forms.textarea label="{{ __('Custom MySQL configuration') }}" rows="10" id="mysqlConf"
                canGate="update" :canResource="$database" />
        </x-application.settings-section>

        <x-application.settings-section title="{{ __('Log delivery') }}"
            description="{{ __('Forward container logs to the drain configured on the server.') }}">
            <x-forms.listbox canGate="update" :canResource="$database" id="isLogDrainEnabled" label="{{ __('Log drain') }}" live onChange="instantSaveAdvanced"
                :disabled="! auth()->user()->can('update', $database)" :options="[
                    ['value' => false, 'label' => __('Do not forward logs')],
                    ['value' => true, 'label' => __('Forward logs to the server drain')],
                ]" />
        </x-application.settings-section>
    </form>
</div>
