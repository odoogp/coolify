<div>
    <x-slot:title>
        {{ __('Teams | Coolify') }}
    </x-slot>

    <x-team.settings-layout>
    <div class="flex flex-col gap-6">
        <form wire:submit="submit" class="application-settings-form">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="{{ __('General') }}"
                description="{{ __('Manage this team\'s identity and shared API access.') }}">
                <x-slot:actions>
                    <x-modal-input buttonTitle="New team" title="{{ __('New Team') }}">
                        <livewire:team.create />
                    </x-modal-input>
                </x-slot:actions>
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input id="name" label="{{ __('Name') }}" required canGate="update" :canResource="$team" />
                    <x-forms.input id="description" label="{{ __('Description') }}" canGate="update" :canResource="$team" />
                    <div class="lg:col-span-2">
                        <x-forms.listbox canGate="update" :canResource="$team" id="is_mcp_server_enabled" label="{{ __('MCP server') }}"
                            helper="{{ __('Controls whether this team\'s API tokens can use the instance MCP endpoint.') }}"
                            :disabled="! auth()->user()->can('update', $team)" :options="[
                                ['value' => false, 'label' => __('Disabled for this team')],
                                ['value' => true, 'label' => __('Enabled for this team')],
                            ]" />
                    </div>
                </div>
            </x-application.settings-section>
        </form>

    </div>
    </x-team.settings-layout>
</div>
