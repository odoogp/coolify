<div>
    <x-slot:title>
        {{ __('Discord Notifications | Coolify') }}
    </x-slot>

    <x-notification.settings-layout>
    <div class="application-settings-form flex flex-col gap-6">
        <form wire:submit="submit">
            <x-unsaved-bar action="submit" />
            <x-application.settings-section title="{{ __('Discord') }}"
                description="{{ __('Send team notifications to a Discord channel through an incoming webhook.') }}">
                <x-slot:actions>
                    <x-notification.channel-actions :enabled="$discordEnabled" enabledProperty="discordEnabled"
                        toggleMethod="instantSaveDiscordEnabled" :canUpdate="auth()->user()->can('update', $settings)" />
                </x-slot:actions>

                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.listbox canGate="update" :canResource="$settings" id="discordPingEnabled" label="{{ __('Critical event mention') }}"
                        helper="{{ __('Mention @here when a critical event occurs.') }}"
                        onChange="instantSaveDiscordPingEnabled"
                        :disabled="!auth()->user()->can('update', $settings)" :options="[
                            ['value' => true, 'label' => __('Mention @here')],
                            ['value' => false, 'label' => __('Do not mention')],
                        ]" />
                    <div class="lg:col-span-2">
                        @can('update', $settings)
                            <x-forms.input type="password" required id="discordWebhookUrl" label="{{ __('Webhook URL') }}"
                                helper="{{ __('Create an incoming webhook in your Discord server settings.') }}" />
                        @else
                            <x-forms.input disabled label="{{ __('Webhook URL') }}" value="Hidden (only admins can view)" />
                        @endcan
                    </div>
                </div>
            </x-application.settings-section>
        </form>

        <x-notification.event-grid :settings="$settings" channel="discord" />
    </div>
    </x-notification.settings-layout>
</div>
