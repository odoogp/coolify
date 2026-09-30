<div>
    <x-slot:title>
        {{ __('Settings | Coolify') }}
    </x-slot>

    <x-settings.layout>
        <form wire:submit="submit" class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            {{-- instance_timezone auto-saves via $wire.set + submit; exclude it so
                 the bar does not flash while the snapshot catches up. --}}
            <x-unsaved-bar action="submit"
                targets="fqdn,instance_name,public_ipv4,public_ipv6,dev_helper_version" />
            <x-application.settings-section title="{{ __('General') }}">
                <div class="grid gap-4 lg:grid-cols-2">
                    <div @class([
                        'lg:col-span-2' => !str_starts_with(strtolower($fqdn ?? ''), 'https://'),
                    ])>
                        <x-forms.input canGate="update" :canResource="$settings" id="fqdn" label="{{ __('URL') }}"
                            helper="Enter the full URL of the instance (for example, https://dashboard.example.com).<br><br><span class='text-coollabs dark:text-warning'>{{ __('Important:') }}</span> Include <b>https://</b> to secure the dashboard with HTTPS."
                            placeholder="https://coolify.yourdomain.com" />
                    </div>

                    @if (str_starts_with(strtolower($fqdn ?? ''), 'https://'))
                        <div>
                            <x-forms.listbox canGate="update" :canResource="$settings"
                                id="is_dashboard_force_https_enabled" label="{{ __('Redirect HTTP to HTTPS') }}"
                                onChange="submit"
                                helper="{{ __('Disable only when Cloudflare Tunnel or another proxy connects to Coolify over HTTP. Keep enabled when Cloudflare uses Full or Full (Strict) SSL.') }}"
                                :options="[
                                    ['value' => true, 'label' => __('Enabled')],
                                    ['value' => false, 'label' => __('Disabled')],
                                ]" />
                        </div>
                    @endif

                    <x-forms.input canGate="update" :canResource="$settings" id="instance_name" label="{{ __('Name') }}"
                        placeholder="{{ product_name() }}" helper="{{ product_text('Custom name for this Coolify instance.') }}" />

                    {{-- Use searchable-listbox so the label row (h-4) and control height match
                         sibling x-forms.input fields (Name). onChange auto-saves like before. --}}
                    <x-forms.searchable-listbox id="instance_timezone" label="{{ __('Instance timezone') }}"
                        helper="{{ __('Timezone used for update checks and the automatic update schedule.') }}"
                        searchPlaceholder="Search timezones" emptyText="No matching timezone"
                        onChange="submit" :options="collect($this->timezones)->map(fn ($timezone) => [
                            'value' => $timezone,
                            'label' => $timezone,
                        ])->all()" :disabled="! auth()->user()->can('update', $settings)" />
                </div>
            </x-application.settings-section>

            <x-application.settings-section title="{{ __('Network addresses') }}">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input canGate="update" :canResource="$settings" id="public_ipv4" type="password"
                        label="{{ __('Instance public IPv4') }}"
                        helper="{{ __('Set this when Coolify cannot detect the correct public IPv4 address.') }}"
                        placeholder="1.2.3.4" autocomplete="new-password" />
                    <x-forms.input canGate="update" :canResource="$settings" id="public_ipv6" type="password"
                        label="{{ __('Instance public IPv6') }}"
                        helper="{{ __('Set this when Coolify cannot detect the correct public IPv6 address.') }}"
                        placeholder="2001:db8::1" autocomplete="new-password" />
                </div>
            </x-application.settings-section>

            @if (isDev())
                <x-application.settings-section title="{{ __('Development helper') }}">
                    <x-forms.input canGate="update" :canResource="$settings" id="dev_helper_version"
                        label="{{ __('Version override') }}"
                        helper="Override the default coolify-helper image version. Leave empty to use {{ config('constants.coolify.helper_version') }}."
                        placeholder="{{ config('constants.coolify.helper_version') }}" />
                </x-application.settings-section>
            @endif
        </form>

    <x-domain-conflict-modal :conflicts="$domainConflicts" :showModal="$showDomainConflictModal"
        confirmAction="confirmDomainUsage">
        <x-slot:consequences>
            <ul class="mt-2 ml-4 list-disc">
                <li>{{ __('The Coolify instance domain will conflict with existing resources.') }}</li>
                <li>{{ __('SSL certificates might not work correctly.') }}</li>
                <li>{{ __('Routing behavior will be unpredictable.') }}</li>
                <li>{{ __('You may not be able to access the Coolify dashboard properly.') }}</li>
            </ul>
        </x-slot:consequences>
    </x-domain-conflict-modal>
    </x-settings.layout>
</div>
