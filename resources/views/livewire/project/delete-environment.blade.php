<x-modal-confirmation title="{{ __('Confirm Environment Deletion?') }}" buttonTitle="Delete" isErrorButton
    submitAction="delete" :actions="$actions"
    :checkboxes="$checkboxes"
    confirmationLabel="{{ __('Please confirm the execution of the actions by entering the Environment Name below') }}"
    shortConfirmationLabel="{{ __('Environment Name') }}" confirmationText="{{ $environmentName }}" :confirmWithPassword="false"
    step2ButtonText="Permanently Delete" />
