<div class="application-settings-form">
    <form class="flex flex-col gap-4" wire:submit="createPrivateKey">
        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input id="name" label="{{ __('Name') }}" required />
            <x-forms.input id="description" label="{{ __('Description') }}" />
            <div class="lg:col-span-2">
                <x-forms.textarea realtimeValidation id="value" rows="10" monospace
                    placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" label="{{ __('Private key') }}" required />
            </div>
            <div class="lg:col-span-2">
                <x-forms.input id="publicKey" readonly label="{{ __('Public key') }}"
                    helper="{{ __('Copy this value to ~/.ssh/authorized_keys on the target server.') }}" />
            </div>
        </div>
        <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
            <button type="submit"
                class="button button-highlighted">
                {{ __('Continue') }}
            </button>
        </div>
    </form>
</div>
