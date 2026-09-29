<form wire:submit="submit" class="application-settings-form flex flex-col gap-4 px-4 py-4">
    <x-unsaved-bar action="submit" />
    <div class="flex items-end gap-2">
        <x-forms.input id="filename" label="{{ __('Filename') }}" />
        <x-modal-confirmation title="{{ __('Delete initialization script?') }}" buttonTitle="Delete" isErrorButton
            submitAction="delete" :actions="[
                'Permanently delete this initialization script from the database and server.',
                'Redeployments that depend on this script may fail.',
            ]" confirmationText="{{ $filename }}"
            confirmationLabel="{{ __('Enter the initialization script name to confirm.') }}"
            shortConfirmationLabel="{{ __('Script name') }}" :confirmWithPassword="false"
            step2ButtonText="Permanently delete" />
    </div>
    <x-forms.textarea id="content" label="{{ __('Content') }}" rows="12" />
</form>
