<div wire:poll.10000ms="refreshStatus">
    <x-status-summary :status="$database->status" title="{{ __('Database status') }}" />
</div>
