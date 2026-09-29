<div>
    <x-slot:title>
        {{ __('Update Settings | Coolify') }}
    </x-slot>

    <x-settings.layout>
        <form wire:submit="submit" class="application-settings-form flex min-w-0 flex-col gap-6">
            {{-- Exclude is_auto_update_enabled (instantSave) so the bar does not flash. --}}
            <x-unsaved-bar action="submit" targets="update_check_frequency,auto_update_frequency" />

            <x-application.settings-section title="{{ __('Update Coolify') }}"
                helper="{{ __('Install the latest Coolify version manually when an update is available.') }}">
                <livewire:upgrade :full-button="true" key="settings-upgrade" />
            </x-application.settings-section>

            <x-application.settings-section title="{{ __('Update checks') }}">
                <x-slot:actions>
                    <x-forms.button type="button" wire:click="checkManually">
                        <x-reicon name="refresh" class="size-3.5" />
                        {{ __('Check now') }}
                    </x-forms.button>
                </x-slot:actions>
                <x-forms.input required id="update_check_frequency" label="{{ __('Check frequency') }}"
                    placeholder="0 * * * *"
                    helper="{{ __('A cron expression or preset such as hourly, daily, weekly, monthly, or yearly.') }}" />
            </x-application.settings-section>

            <x-application.settings-section title="{{ __('Automatic updates') }}">
                <div class="grid gap-4 lg:grid-cols-2">
                    @if (!is_null(config('constants.coolify.autoupdate', null)))
                        <x-forms.listbox disabled id="is_auto_update_enabled" label="{{ __('Automatic updates') }}"
                            helper="{{ __('Controlled by the AUTOUPDATE environment variable.') }}" :options="[
                                ['value' => true, 'label' => __('Enabled')],
                                ['value' => false, 'label' => __('Disabled')],
                            ]" />
                    @else
                        <x-forms.listbox id="is_auto_update_enabled" label="{{ __('Automatic updates') }}"
                            onChange="instantSave" :options="[
                                ['value' => true, 'label' => __('Enabled')],
                                ['value' => false, 'label' => __('Disabled')],
                            ]" />
                    @endif

                    @if (is_null(config('constants.coolify.autoupdate', null)) && $is_auto_update_enabled)
                        <x-forms.input required id="auto_update_frequency" label="{{ __('Update frequency') }}"
                            placeholder="0 0 * * *"
                            helper="{{ __('Cron expression or preset for installing updates.') }}" />
                    @else
                        <x-forms.input label="{{ __('Update frequency') }}" disabled placeholder="{{ __('Disabled') }}" />
                    @endif
                </div>
            </x-application.settings-section>

            <x-application.settings-section title="{{ __('Image registry') }}">
                <div class="max-w-md">
                    <x-forms.listbox id="docker_registry_url" label="{{ __('Docker registry') }}" :options="[
                        ['value' => 'docker.io', 'label' => __('Docker Hub')],
                        ['value' => 'ghcr.io', 'label' => __('GitHub Container Registry')],
                    ]"
                        helper="{{ __('Switch registries if the current source is rate limited.') }}" />
                </div>
            </x-application.settings-section>
        </form>
    </x-settings.layout>
</div>
