@props([
    'settings',
    'channel',
    'threaded' => false,
])

@php
    $eventGroups = [
        'Deployments' => [
            ['key' => 'deploymentSuccess', 'label' => __('Deployment success')],
            ['key' => 'deploymentFailure', 'label' => __('Deployment failure')],
            [
                'key' => 'statusChange',
                'label' => __('Container status changes'),
                'helper' => __('Notify when a container stops or restarts.'),
            ],
        ],
        'Backups' => [
            ['key' => 'backupSuccess', 'label' => __('Backup success')],
            ['key' => 'backupFailure', 'label' => __('Backup failure')],
        ],
        'Scheduled tasks' => [
            ['key' => 'scheduledTaskSuccess', 'label' => __('Scheduled task success')],
            ['key' => 'scheduledTaskFailure', 'label' => __('Scheduled task failure')],
        ],
        'Servers' => [
            ['key' => 'dockerCleanupSuccess', 'label' => __('Docker cleanup success')],
            ['key' => 'dockerCleanupFailure', 'label' => __('Docker cleanup failure')],
            ['key' => 'serverDiskUsage', 'label' => __('Disk usage warning')],
            ['key' => 'serverReachable', 'label' => __('Server reachable')],
            ['key' => 'serverUnreachable', 'label' => __('Server unreachable')],
            ['key' => 'serverPatch', 'label' => __('Server patching')],
            ['key' => 'traefikOutdated', 'label' => __('Traefik proxy outdated')],
        ],
    ];

    $enabledThreadEvents = [];
    if ($threaded) {
        foreach ($eventGroups as $group => $events) {
            foreach ($events as $event) {
                $enabled = (bool) data_get(
                    $settings,
                    Str::snake($event['key'] . '_' . $channel . '_notifications'),
                );

                if (! $enabled) {
                    continue;
                }

                $enabledThreadEvents[] = [
                    'group' => $group,
                    'key' => $event['key'],
                    'label' => $event['label'],
                    'threadModel' => Str::camel(
                        Str::studly($channel) . 'Notifications' . Str::studly($event['key']) . 'ThreadId',
                    ),
                ];
            }
        }

        $enabledThreadEventsByGroup = collect($enabledThreadEvents)->groupBy('group');
    }
@endphp

<div class="flex flex-col gap-6">
    <x-application.settings-section title="{{ __('Notification events') }}"
        description="{{ __('Choose which events send a notification on this channel.') }}">
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ($eventGroups as $group => $events)
                @php
                    $multiselectEvents = collect($events)
                        ->map(fn ($event) => [
                            'property' => $event['key'] . Str::studly($channel) . 'Notifications',
                            'label' => $event['label'],
                            'enabled' => (bool) data_get(
                                $settings,
                                Str::snake($event['key'] . '_' . $channel . '_notifications'),
                            ),
                        ])
                        ->all();
                    $groupId = Str::slug($channel . '-' . $group . '-events');
                @endphp
                <div class="min-w-0">
                    <x-notification.event-multiselect :settings="$settings" :id="$groupId"
                        :label="$group" :events="$multiselectEvents" />
                </div>
            @endforeach
        </div>
    </x-application.settings-section>

    @if ($threaded)
        <x-application.settings-section title="{{ __('Forum topics') }}"
            description="{{ __('Optional. Route enabled events to a Telegram forum topic using its message thread ID. Leave blank to post in the main chat.') }}">
            @if ($enabledThreadEvents === [])
                <p class="text-[13px] leading-relaxed text-neutral-500 dark:text-fg-dim">
                    {{ __('Enable one or more events above to assign forum topic IDs.') }}
                </p>
            @else
                <div class="flex flex-col gap-5">
                    @foreach ($enabledThreadEventsByGroup as $group => $events)
                        <div class="min-w-0">
                            <div
                                class="mb-2 text-[11px] font-medium tracking-wide text-neutral-500 uppercase dark:text-fg-dim">
                                {{ $group }}
                            </div>
                            <div
                                class="divide-y divide-neutral-200 overflow-hidden rounded-xl border border-neutral-200 dark:divide-white/[0.06] dark:border-white/[0.08]">
                                @foreach ($events as $event)
                                    <div
                                        class="grid gap-2 px-3.5 py-3 sm:grid-cols-[minmax(0,1fr)_minmax(10rem,14rem)] sm:items-center sm:gap-4"
                                        wire:key="{{ $channel }}-thread-row-{{ $event['key'] }}">
                                        <div class="min-w-0">
                                            <div
                                                class="truncate text-[13px] font-medium text-black dark:text-fg">
                                                {{ $event['label'] }}
                                            </div>
                                            <div class="text-[11px] text-neutral-500 dark:text-fg-dim">
                                                {{ __('Topic ID') }}
                                            </div>
                                        </div>
                                        <x-forms.input wire:key="{{ $channel }}-thread-{{ $event['key'] }}"
                                            canGate="update" :canResource="$settings" type="password"
                                            :id="$event['threadModel']" :label="null"
                                            :placeholder="'Optional'"
                                            :aria-label="$event['label'] . ' topic ID'" />
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-application.settings-section>
    @endif
</div>
