<div class="application-settings-form">
    <form class="flex flex-col gap-4" wire:submit="createPrivateKey">
        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input id="name" label="{{ __('Name') }}" required />
            <x-forms.input id="description" label="{{ __('Description') }}" />
            <div class="lg:col-span-2 flex flex-col gap-2">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-[11px] text-neutral-500 dark:text-fg-faint">
                        {{ __('No key yet? Generate one here. Paste a key only if you already have one.') }}
                    </p>
                    <button type="button" class="button button-highlighted" wire:click="generateKey"
                        wire:loading.attr="disabled" wire:target="generateKey">
                        {{ __('Generate key') }}
                    </button>
                </div>
                <x-forms.textarea realtimeValidation id="value" rows="10" monospace
                    placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" label="{{ __('Private key') }}" />
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
