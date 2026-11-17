<div>
    <x-slot:title>
        {{ __('Transactional Email | Coolify') }}
    </x-slot>

    <x-settings.layout>
    <div class="application-settings-form mx-auto flex w-full max-w-none min-w-0 flex-col gap-6">
        {{-- One bar for the whole page. Three stacked bars made Save run
             submitResend(), which required an API key even when Resend was off. --}}
        <x-unsaved-bar action="submit"
            targets="smtpFromName,smtpFromAddress,smtpHost,smtpPort,smtpEncryption,smtpUsername,smtpPassword,smtpTimeout,smtpEhloDomain,resendApiKey" />

        <form wire:submit="submit">
            <x-application.settings-section title="{{ __('Sender') }}">
                <x-slot:actions>
                    @include('livewire.partials.settings-email-send-test')
                </x-slot:actions>
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input required id="smtpFromName" helper="{{ __('Name shown in outgoing email.') }}"
                        label="{{ __('From name') }}" />
                    <x-forms.input required id="smtpFromAddress" helper="{{ __('Address used for outgoing email.') }}"
                        label="{{ __('From address') }}" />
                </div>
            </x-application.settings-section>
        </form>

        <form wire:submit.prevent="submitSmtp">
            <x-application.settings-section title="{{ __('SMTP server') }}">
                <div class="grid gap-4 lg:grid-cols-3">
                    <div class="lg:col-span-3">
                        <div class="w-full sm:w-72">
                            <x-forms.listbox id="smtpEnabled" label="{{ __('SMTP delivery') }}"
                                onChange="instantSaveSmtp" :options="[
                                    ['value' => true, 'label' => __('Enabled')],
                                    ['value' => false, 'label' => __('Disabled')],
                                ]" />
                        </div>
                    </div>
                    <x-forms.input required id="smtpHost" placeholder="{{ __('smtp.mailgun.org') }}" label="{{ __('Host') }}" />
                    <x-forms.input required id="smtpPort" type="number" placeholder="587" label="{{ __('Port') }}" />
                    <x-forms.listbox required id="smtpEncryption" label="{{ __('Encryption') }}" :options="[
                        ['value' => 'starttls', 'label' => __('StartTLS')],
                        ['value' => 'tls', 'label' => __('TLS / SSL')],
                        ['value' => 'none', 'label' => __('None')],
                    ]" />
                    <x-forms.input id="smtpUsername" label="{{ __('Username') }}" />
                    <x-forms.input id="smtpPassword" type="password" label="{{ __('Password') }}"
                        autocomplete="new-password" />
                    <x-forms.input id="smtpTimeout" type="number"
                        helper="{{ __('Maximum delivery time in seconds.') }}" label="{{ __('Timeout') }}" />
                    <x-forms.input id="smtpEhloDomain" placeholder="{{ __('coolify.example.com') }}"
                        helper="{{ __('Fully qualified domain sent in the SMTP EHLO command. Uses the system default when empty.') }}"
                        label="{{ __('EHLO domain') }}" />
                </div>
            </x-application.settings-section>
        </form>

        <form wire:submit.prevent="submitResend">
            <x-application.settings-section title="{{ __('Resend') }}">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.listbox id="resendEnabled" label="{{ __('Resend delivery') }}"
                        onChange="instantSaveResend" :options="[
                            ['value' => true, 'label' => __('Enabled')],
                            ['value' => false, 'label' => __('Disabled')],
                        ]" />
                    <x-forms.input type="password" id="resendApiKey" placeholder="{{ __('API key') }}"
                        :required="$resendEnabled" label="{{ __('API key') }}" autocomplete="new-password" />
                </div>
            </x-application.settings-section>
        </form>
    </div>
    </x-settings.layout>
</div>
