<form class="flex w-full flex-col gap-4" wire:submit="submit">
    <div class="grid gap-4 sm:grid-cols-2">
        <x-forms.input placeholder="{{ __('Database cleanup') }}" id="name" label="{{ __('Name') }}" />
        <x-forms.input placeholder="0 0 * * * or daily"
            helper="{{ __('Use every_minute, hourly, daily, weekly, monthly, yearly, or a cron expression.') }}"
            id="frequency" label="{{ __('Schedule') }}" />
    </div>

    <x-forms.input placeholder="{{ __('php artisan schedule:run') }}" id="command" label="{{ __('Command') }}" />

    <div class="grid gap-4 sm:grid-cols-2">
        <x-forms.input type="number" placeholder="300" id="timeout"
            helper="{{ __('Maximum execution time from 60 to 36,000 seconds.') }}" label="{{ __('Timeout (seconds)') }}" />
        @if ($type === 'application' && $containerNames->count() > 1)
            <x-forms.listbox id="container" label="{{ __('Container') }}"
                :options="$containerNames->map(fn ($containerName) => [
                    'value' => $containerName,
                    'label' => $containerName,
                ])->values()->all()" />
        @elseif ($type === 'service')
            <x-forms.listbox id="container" label="{{ __('Container') }}"
                :options="$containerNames->map(fn ($containerName) => [
                    'value' => $containerName,
                    'label' => $containerName,
                ])->values()->all()" />
        @else
            <x-forms.input placeholder="{{ __('php') }}" id="container"
                helper="{{ __('Leave empty when the resource only has one container.') }}" label="{{ __('Container') }}" />
        @endif
    </div>

    <div class="flex justify-end border-t border-neutral-200 pt-4 dark:border-white/[0.07]">
        <x-forms.button @click="modalOpen=false" type="submit">
            {{ __('Add task') }}
        </x-forms.button>
    </div>
</form>
