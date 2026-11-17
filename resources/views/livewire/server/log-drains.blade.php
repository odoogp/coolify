<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Log Drains | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="log-drains" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            @if ($server->isFunctional())
                <x-application.settings-section id="server-log-drains-overview-section" title="{{ __('Log drains') }}"
                    helper="{{ __('Forward container logs from this server to one external destination.') }}">
                    <x-slot:actions>
                        <x-status-badge :status="$server->isLogDrainEnabled() ? 'Active' : 'Not configured'"
                            :type="$server->isLogDrainEnabled() ? 'success' : 'neutral'" />
                    </x-slot:actions>
                    <p class="text-sm leading-6 text-neutral-600 dark:text-fg-dim">
                        {{ __('Only one log drain can be active at a time. Disable the current destination before enabling') }}
                        another provider.
                    </p>
                </x-application.settings-section>

                <form wire:submit="submit" class="contents">
                    <x-unsaved-bar action="submit" />

                    <x-application.settings-section id="server-new-relic-drain-section" title="{{ __('New Relic') }}"
                        helper="{{ __('Send logs through the New Relic Log API.') }}">
                        <div class="grid gap-4 lg:grid-cols-3">
                            <x-forms.listbox canGate="update" :canResource="$server" id="isLogDrainNewRelicEnabled" label="{{ __('Status') }}"
                                onChange="instantSave" :options="[
                                    ['value' => false, 'label' => __('Disabled')],
                                    ['value' => true, 'label' => __('Enabled')],
                                ]"
                                :disabled="$isLogDrainAxiomEnabled || $isLogDrainCustomEnabled || !auth()->user()->can('update', $server)" />
                            <x-forms.input canGate="update" :canResource="$server" type="password" required
                                id="logDrainNewRelicLicenseKey" label="{{ __('License key') }}"
                                :disabled="$server->isLogDrainEnabled()" />
                            <x-forms.input canGate="update" :canResource="$server" required
                                id="logDrainNewRelicBaseUri" label="{{ __('Endpoint') }}"
                                placeholder="https://log-api.eu.newrelic.com/log/v1"
                                helper="{{ __('Use the EU or US New Relic Log API endpoint.') }}"
                                :disabled="$server->isLogDrainEnabled()" />
                        </div>
                    </x-application.settings-section>
                    <x-application.settings-section id="server-axiom-drain-section" title="{{ __('Axiom') }}"
                        helper="{{ __('Send logs to an Axiom dataset using its ingest API.') }}">
                        <div class="grid gap-4 lg:grid-cols-3">
                            <x-forms.listbox canGate="update" :canResource="$server" id="isLogDrainAxiomEnabled" label="{{ __('Status') }}"
                                onChange="instantSave" :options="[
                                    ['value' => false, 'label' => __('Disabled')],
                                    ['value' => true, 'label' => __('Enabled')],
                                ]"
                                :disabled="$isLogDrainNewRelicEnabled || $isLogDrainCustomEnabled || !auth()->user()->can('update', $server)" />
                            <x-forms.input canGate="update" :canResource="$server" type="password" required
                                id="logDrainAxiomApiKey" label="{{ __('API key') }}"
                                :disabled="$server->isLogDrainEnabled()" />
                            <x-forms.input canGate="update" :canResource="$server" required
                                id="logDrainAxiomDatasetName" label="{{ __('Dataset name') }}"
                                :disabled="$server->isLogDrainEnabled()" />
                        </div>
                    </x-application.settings-section>
                    <x-application.settings-section id="server-custom-drain-section" title="{{ __('Custom Fluent Bit') }}"
                        helper="{{ __('Provide a custom Fluent Bit output and optional parser configuration.') }}">
                        <div class="mb-4 max-w-sm">
                            <x-forms.listbox canGate="update" :canResource="$server" id="isLogDrainCustomEnabled" label="{{ __('Status') }}"
                                onChange="instantSave" :options="[
                                    ['value' => false, 'label' => __('Disabled')],
                                    ['value' => true, 'label' => __('Enabled')],
                                ]"
                                :disabled="$isLogDrainNewRelicEnabled || $isLogDrainAxiomEnabled || !auth()->user()->can('update', $server)" />
                        </div>
                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-forms.textarea canGate="update" :canResource="$server" rows="8" required
                                id="logDrainCustomConfig" label="{{ __('Fluent Bit configuration') }}"
                                :disabled="$server->isLogDrainEnabled()" />
                            <x-forms.textarea canGate="update" :canResource="$server" rows="8"
                                id="logDrainCustomConfigParser" label="{{ __('Parser configuration') }}"
                                :disabled="$server->isLogDrainEnabled()" />
                        </div>
                    </x-application.settings-section>
                </form>
            @else
                <x-application.settings-section title="{{ __('Log drains') }}"
                    helper="{{ __('Forward container logs from this server to an external destination.') }}">
                    <x-empty size="sm" title="{{ __('Server validation required') }}"
                        description="{{ __('Validate this server before configuring log drains.') }}"
                        icon-name="notifications" />
                </x-application.settings-section>
            @endif
        </div>
    </div>
</div>
