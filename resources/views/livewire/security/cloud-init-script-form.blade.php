<form wire:submit="save" class="application-settings-form flex w-full flex-col gap-4">
    <x-forms.input id="name" label="{{ __('Script name') }}" helper="{{ __('A recognizable name for this reusable script.') }}" required />
    <x-forms.textarea id="script" label="{{ __('Script content') }}" rows="12" monospace
        helper="{{ __('Cloud-config YAML or another script accepted by your provider.') }}" required />
    <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
        <button type="submit"
            class="button button-highlighted">
            {{ $scriptId ? __('Update script') : __('Create script') }}
        </button>
    </div>
</form>
