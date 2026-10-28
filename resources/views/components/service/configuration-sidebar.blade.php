@props(['service', 'currentRoute'])

@php
    $service->loadMissing('environment.project.odooProfile');
    $odooIsOdoo = $service->supportsOdooJupyter();
    $serviceRouteParameters = [
        'project_uuid' => $service->environment->project->uuid,
        'environment_uuid' => $service->environment->uuid,
        'service_uuid' => $service->uuid,
    ];
    $odooEnvParameters = [
        'project_uuid' => $serviceRouteParameters['project_uuid'],
        'environment_uuid' => $serviceRouteParameters['environment_uuid'],
    ];
    $backupsRoute = $odooIsOdoo ? 'project.odoo.backups' : 'project.service.volume-backups.index';
    $canMigrate = $odooIsOdoo
        && $service->environment?->project?->odooProfile !== null
        && auth()->user()?->can('update', $service->environment->project);
    $clientOdooNav = $odooIsOdoo && ! isInstanceOwner();

    $configurationItems = collect([
        ['label' => __('Deployments'), 'route' => 'project.service.deployments', 'icon' => 'time-back', 'visible' => $odooIsOdoo],
        ['label' => __('General'), 'route' => 'project.service.configuration', 'icon' => 'settings'],
        ['label' => __('Domains'), 'route' => 'project.service.domains', 'icon' => 'globe'],
        ['label' => __('Keys'), 'route' => 'project.environment.keys', 'icon' => 'keys'],
        ['label' => __('Environment Variables'), 'route' => 'project.service.environment-variables', 'icon' => 'variables', 'hasWarning' => ! $service->isDeployable],
        ['label' => __('Persistent Storage'), 'route' => 'project.service.storages', 'icon' => 'storages'],
        ['label' => __('Migrate'), 'route' => 'project.odoo.migrate', 'icon' => 'layers', 'visible' => $canMigrate],
        ['label' => __('Backups'), 'route' => $backupsRoute, 'icon' => 'database'],
        ['label' => __('Runtime Logs'), 'route' => 'project.service.logs', 'icon' => 'unordered-list', 'navigate' => false],
        ['label' => __('Terminal'), 'route' => 'project.service.command', 'icon' => 'browser-terminal', 'navigate' => false, 'visible' => auth()->user()?->canOpenTerminal($service)],
        ['label' => __('Scheduled Tasks'), 'route' => 'project.service.scheduled-tasks.show', 'icon' => 'calendar'],
        ['label' => __('Webhooks'), 'route' => 'project.service.webhooks', 'icon' => 'notifications'],
        ['label' => __('Resource Operations'), 'route' => 'project.service.resource-operations', 'icon' => 'server-update'],
        ['label' => __('Tags'), 'route' => 'project.service.tags', 'icon' => 'tags'],
        ['label' => __('Danger Zone'), 'route' => 'project.service.danger', 'icon' => 'shield-alert'],
    ])->filter(fn (array $item): bool => $item['visible'] ?? true)
        ->when($clientOdooNav, fn ($items) => $items->filter(fn (array $item): bool => in_array($item['route'], [
            'project.service.deployments',
            'project.service.configuration',
            'project.environment.keys',
            'project.odoo.migrate',
            'project.odoo.backups',
            'project.service.logs',
            'project.service.command',
            'project.service.danger',
        ], true)))
        ->map(fn (array $item): array => [
            ...$item,
            'active' => $currentRoute === $item['route']
                || ($item['route'] === 'project.service.scheduled-tasks.show'
                    && str($currentRoute)->startsWith('project.service.scheduled-tasks'))
                || ($item['route'] === 'project.service.volume-backups.index'
                    && str($currentRoute)->startsWith('project.service.volume-backups'))
                || ($item['route'] === 'project.odoo.backups' && $currentRoute === 'project.odoo.backups')
                || ($item['route'] === 'project.odoo.migrate' && $currentRoute === 'project.odoo.migrate')
                || ($item['route'] === 'project.environment.keys' && $currentRoute === 'project.environment.keys'),
        ]);

    $menuGroups = $clientOdooNav
        ? [
            'Updates' => ['Deployments'],
            'Settings' => ['General', 'Keys'],
            'Observe & troubleshoot' => ['Runtime Logs', 'Terminal'],
            'Automation' => ['Migrate', 'Backups'],
            'Operations' => ['Danger Zone'],
        ]
        : [
            'Updates' => ['Deployments'],
            'Settings' => ['General', 'Domains', 'Keys', 'Environment Variables', 'Persistent Storage'],
            'Observe & troubleshoot' => ['Runtime Logs', 'Terminal'],
            'Automation' => ['Migrate', 'Scheduled Tasks', 'Webhooks', 'Backups'],
            'Operations' => ['Resource Operations', 'Tags', 'Danger Zone'],
        ];

    $groupedItems = collect($menuGroups)
        ->map(fn (array $labels) => collect($labels)
            ->map(fn (string $label) => $configurationItems->firstWhere('label', __($label))
                ?? $configurationItems->firstWhere('label', $label))
            ->filter()
            ->values())
        ->filter(fn ($items) => $items->isNotEmpty());
@endphp

<aside class="application-settings-navigation min-w-0 xl:self-start">
    <nav aria-label="{{ __('Service settings') }}"
        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
        @foreach ($groupedItems as $groupLabel => $groupItems)
            @unless ($loop->first)
                <div class="my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]" aria-hidden="true"></div>
            @endunless
            <div class="nav-section hidden xl:block">{{ $groupLabel }}</div>
            @foreach ($groupItems as $menuItem)
                @php
                    $menuRouteParameters = in_array($menuItem['route'], ['project.odoo.backups', 'project.odoo.migrate', 'project.environment.keys'], true)
                        ? $odooEnvParameters
                        : $serviceRouteParameters;
                @endphp
                <a @class(['menu-item', 'menu-item-active' => $menuItem['active']])
                    @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                    href="{{ route($menuItem['route'], $menuRouteParameters) }}">
                    <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                    <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                    @if ($menuItem['hasWarning'] ?? false)
                        <span class="ml-auto size-2 shrink-0 rounded-full bg-error" title="{{ __('Required environment variables missing') }}"></span>
                    @endif
                </a>
            @endforeach
            @if ($odooIsOdoo && $groupLabel === 'Updates')
                <a @class([
                    'menu-item',
                    'menu-item-active' => $currentRoute === 'project.service.configuration'
                        && request()->query('odooPanel') === 'github',
                ])
                    {{ wireNavigate() }}
                    href="{{ route('project.service.configuration', [...$serviceRouteParameters, 'odooPanel' => 'github']) }}">
                    <x-reicon name="sources" class="menu-item-icon" />
                    <span class="menu-item-label">{{ __('GitHub') }}</span>
                </a>
                @if (isInstanceOwner())
                    <a @class([
                        'menu-item',
                        'menu-item-active' => $currentRoute === 'project.service.configuration'
                            && request()->query('odooPanel') === 'owner-package',
                    ])
                        {{ wireNavigate() }}
                        href="{{ route('project.service.configuration', [...$serviceRouteParameters, 'odooPanel' => 'owner-package']) }}">
                        <x-reicon name="layers" class="menu-item-icon" />
                        <span class="menu-item-label">{{ __('Owner package') }}</span>
                    </a>
                @endif
            @endif
        @endforeach
    </nav>
</aside>
