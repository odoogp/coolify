<div>
    <x-slot:title>
        {{ __('WhatsApp | Coolify') }}
    </x-slot>

    <x-settings.layout>
        <form wire:submit="submit" class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <x-unsaved-bar action="submit" targets="whatsapp_support_number" />
            <x-application.settings-section title="{{ __('WhatsApp') }}"
                description="{{ __('The floating button sends the conversation to this number. It stays hidden until a number is saved.') }}">
                <div class="max-w-xl">
                    <x-forms.input canGate="update" :canResource="$settings" id="whatsapp_support_number"
                        label="{{ __('WhatsApp support number') }}"
                        placeholder="50376120078"
                        helper="{{ __('Country code and number, digits only. For example 50376120078.') }}" />
                </div>
            </x-application.settings-section>
        </form>
    </x-settings.layout>
</div>
