<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Advanced | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="advanced" />

        <form wire:submit="submit" class="application-settings-form flex w-full flex-col gap-6">
            <x-unsaved-bar action="submit" />

            <x-application.settings-section id="server-disk-usage-section" title="{{ __('Disk usage') }}"
                helper="{{ __('Control when Coolify checks this server and when your team is notified.') }}">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.input canGate="update" :canResource="$server" placeholder="0 23 * * *"
                        id="serverDiskUsageCheckFrequency" label="{{ __('Check frequency') }}" required
                        helper="{{ __('Cron expression or preset such as hourly, daily, weekly, monthly, or yearly.') }}" />
                    <x-forms.input canGate="update" :canResource="$server"
                        id="serverDiskUsageNotificationThreshold" type="number" min="1" max="99"
                        label="{{ __('Notification threshold') }}" required
                        helper="{{ __('Notify the team when root filesystem usage exceeds this percentage.') }}" />
                </div>
            </x-application.settings-section>

            <x-application.settings-section id="server-backups-section" title="{{ __('Backups') }}"
                helper="{{ __('Limit how much CPU volume backup compression may use on this server.') }}">
                <x-forms.listbox canGate="update" :canResource="$server" id="backupCompressionCpuPercentage"
                    label="{{ __('Backup compression CPU') }}" onChange="instantSave"
                    helper="{{ __('Sets how many CPU threads can be used to compress volume backups, based on this server\'s available CPUs.') }}" :options="[
                        ['value' => 25, 'label' => __('Low (25%)')],
                        ['value' => 50, 'label' => __('Balanced (50%)')],
                        ['value' => 75, 'label' => __('High (75%)')],
                        ['value' => 100, 'label' => __('Maximum (100%)')],
                    ]" />
            </x-application.settings-section>

            <x-application.settings-section id="server-builds-section" title="{{ __('Builds') }}"
                helper="{{ __('Set deployment concurrency, execution timeouts, and queue capacity.') }}">
                <div class="grid gap-4 lg:grid-cols-3">
                    <x-forms.input canGate="update" :canResource="$server" id="concurrentBuilds"
                        type="number" min="1" label="{{ __('Concurrent builds') }}" required
                        helper="{{ __('Maximum deployments that can build at the same time.') }}" />
                    <x-forms.input canGate="update" :canResource="$server" id="dynamicTimeout"
                        type="number" min="1" label="{{ __('Deployment timeout') }}" required
                        helper="{{ __('Maximum deployment duration in seconds.') }}" />
                    <x-forms.input canGate="update" :canResource="$server" id="deploymentQueueLimit"
                        type="number" min="1" label="{{ __('Queue limit') }}" required
                        helper="{{ __('Maximum queued deployments before new requests are rejected.') }}" />
                </div>
            </x-application.settings-section>
        </form>
    </div>
</div>
