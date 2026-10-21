<div @class([
    'logs-viewer-deployment-actions',
    'hidden' => ! in_array(data_get($application_deployment_queue, 'status'), [
        'queued',
        'in_progress',
        'failed',
        'cancelled-by-user',
    ], true),
])>
    <div class="flex flex-wrap items-center justify-end gap-2">
        @if (data_get($application_deployment_queue, 'status') === 'queued')
            <x-forms.button class="logs-viewer-deployment-btn" wire:click.prevent="force_start">{{ __('Force start') }}</x-forms.button>
        @endif
        @if (in_array(data_get($application_deployment_queue, 'status'), ['queued', 'in_progress'], true))
            <x-forms.button isError class="logs-viewer-deployment-btn logs-viewer-cancel-btn"
                wire:click.prevent="cancel">{{ __('Cancel deployment') }}</x-forms.button>
        @endif
        @if (in_array(data_get($application_deployment_queue, 'status'), ['failed', 'cancelled-by-user'], true))
            <x-forms.button class="logs-viewer-deployment-btn" wire:click.prevent="retry"
                wire:confirm="{{ __('Retry this update with the same commit?') }}">
                {{ __('Retry') }}
            </x-forms.button>
        @endif
    </div>
</div>
