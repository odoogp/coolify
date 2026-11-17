<div>
    <x-slot:title>
        {{ data_get_str($service, 'name')->limit(10) }} > Configuration | {{ product_name() }}
    </x-slot>

    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="$query" />

    @php
        $serviceRouteParameters = [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'service_uuid' => $service->uuid,
        ];

        $clientOdooNav = $odooIsOdoo && ! isInstanceOwner();
        $configurationItems = collect([
            ['label' => __('Deployments'), 'route' => 'project.service.deployments', 'icon' => 'time-back', 'visible' => $odooIsOdoo],
            ['label' => __('General'), 'route' => 'project.service.configuration', 'icon' => 'settings'],
            ['label' => __('Domains'), 'route' => 'project.service.domains', 'icon' => 'globe'],
            ['label' => __('Environment Variables'), 'route' => 'project.service.environment-variables', 'icon' => 'variables', 'hasWarning' => ! $service->isDeployable],
            ['label' => __('Persistent Storage'), 'route' => 'project.service.storages', 'icon' => 'storages'],
            ['label' => __('Backups'), 'route' => $odooIsOdoo ? 'project.odoo.backups' : 'project.service.volume-backups.index', 'icon' => 'database'],
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
                'project.odoo.backups',
                'project.service.logs',
                'project.service.command',
                'project.service.danger',
            ], true)))
            ->map(fn (array $item): array => [
                ...$item,
                'active' => $currentRoute === $item['route']
                    || ($item['route'] === 'project.service.scheduled-tasks.show'
                        && str($currentRoute)->startsWith('project.service.scheduled-tasks')),
            ]);

        $menuGroups = $clientOdooNav
            ? [
                'Updates' => ['Deployments'],
                'Settings' => ['General'],
                'Observe & troubleshoot' => ['Runtime Logs', 'Terminal'],
                'Automation' => ['Backups'],
                'Operations' => ['Danger Zone'],
            ]
            : [
                'Updates' => ['Deployments'],
                'Settings' => ['General', 'Domains', 'Environment Variables', 'Persistent Storage'],
                'Observe & troubleshoot' => ['Runtime Logs', 'Terminal'],
                'Automation' => ['Scheduled Tasks', 'Webhooks', 'Backups'],
                'Operations' => ['Resource Operations', 'Tags', 'Danger Zone'],
            ];

        $groupedItems = collect($menuGroups)
            ->map(fn (array $labels) => collect($labels)
                ->map(fn (string $label) => $configurationItems->firstWhere('label', __($label))
                    ?? $configurationItems->firstWhere('label', $label))
                ->filter()
                ->values())
            ->filter(fn ($items) => $items->isNotEmpty());

        // Group that holds the current page — always kept open, even if collapsed before.
        $activeGroup = (string) $groupedItems->search(fn ($items) => $items->contains(fn ($item) => $item['active'] ?? false));

        $storageSections = $applications
            ->concat($databases)
            ->map(fn ($resource): array => [
                'id' => 'storage-service-'.$resource->uuid,
                'label' => Str::headline($resource->name),
            ]);
    @endphp

    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
            <aside class="application-settings-navigation min-w-0 xl:self-start">
                <nav aria-label="{{ __('Service settings') }}"
                    class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
                    @if ($odooIsOdoo)
                        @php
                            $deploymentsItem = $configurationItems->firstWhere('route', 'project.service.deployments');
                        @endphp
                        @if ($deploymentsItem)
                            <a @class(['menu-item', 'menu-item-active' => $deploymentsItem['active']])
                                {{ wireNavigate() }}
                                href="{{ route('project.service.deployments', $serviceRouteParameters) }}">
                                <x-reicon name="time-back" class="menu-item-icon" />
                                <span class="menu-item-label">{{ __('Deployments') }}</span>
                            </a>
                            <div class="my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]"
                                aria-hidden="true"></div>
                        @endif
                        <button type="button" wire:click="$set('odooPanel', 'github')" @class([
                            'menu-item',
                            'menu-item-active' => $currentRoute === 'project.service.configuration' && $odooPanel === 'github',
                        ])>
                            <x-reicon name="sources" class="menu-item-icon" />
                            <span class="menu-item-label">{{ __('GitHub') }}</span>
                        </button>
                        @if (isInstanceOwner())
                            <button type="button" wire:click="$set('odooPanel', 'owner-package')" @class([
                                'menu-item',
                                'menu-item-active' => $currentRoute === 'project.service.configuration' && $odooPanel === 'owner-package',
                            ])>
                                <x-reicon name="layers" class="menu-item-icon" />
                                <span class="menu-item-label">{{ __('Owner package') }}</span>
                            </button>
                        @endif
                    @endif
                    @foreach ($groupedItems as $groupLabel => $groupItems)
                        {{-- Deployments is already rendered above GitHub for Odoo. --}}
                        @continue($odooIsOdoo && $groupLabel === 'Updates')
                        @unless ($loop->first)
                            <div class="my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]"
                                aria-hidden="true"></div>
                        @endunless
                        <button type="button" class="nav-section-toggle hidden xl:flex" @click="toggle(@js($groupLabel))"
                            :aria-expanded="isOpen(@js($groupLabel))">
                            <span>{{ $groupLabel }}</span>
                            <svg class="size-3 shrink-0 opacity-60 transition-transform"
                                :class="!isOpen(@js($groupLabel)) && '-rotate-90'" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                        <div class="contents" :class="isOpen(@js($groupLabel)) ? 'xl:block' : 'xl:hidden'">
                        @foreach ($groupItems as $menuItem)
                            @php
                                $menuRouteParameters = $menuItem['route'] === 'project.odoo.backups'
                                    ? [
                                        'project_uuid' => $serviceRouteParameters['project_uuid'],
                                        'environment_uuid' => $serviceRouteParameters['environment_uuid'],
                                    ]
                                    : $serviceRouteParameters;
                            @endphp
                            <a @class([
                                'menu-item',
                                'menu-item-active' => $menuItem['active'],
                            ])
                                @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                                href="{{ route($menuItem['route'], $menuRouteParameters) }}">
                                <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                                <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                                @if ($menuItem['hasWarning'] ?? false)
                                    <span class="ml-auto size-2 shrink-0 rounded-full bg-error" title="{{ __('Required environment variables missing') }}"></span>
                                @endif
                            </a>
                            @if ($menuItem['active'] && $menuItem['route'] === 'project.service.storages' && $storageSections->isNotEmpty())
                                <div class="nav-children hidden flex-col gap-0.5 py-1 xl:flex"
                                    x-data="{ activeSection: '' }">
                                    @foreach ($storageSections as $section)
                                        <button type="button" class="menu-subitem"
                                            :class="activeSection === '{{ $section['id'] }}' && 'menu-subitem-active'"
                                            @click="activeSection = '{{ $section['id'] }}'; window.scrollToSettingsSection?.('{{ $section['id'] }}')">
                                            <span class="menu-item-label truncate text-left"
                                                title="{{ $section['label'] }}">{{ $section['label'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                        </div>
                    @endforeach
                </nav>
            </aside>

            <div class="min-w-0">
                @if ($currentRoute === 'project.service.configuration')
                    @if ($odooIsOdoo && $odooPanel === 'github')
                        <section class="application-settings-section mb-6">
                            @include('livewire.project.service.partials.odoo-github-panel')
                        </section>
                    @endif
                    @if ($odooIsOdoo && $odooPanel === 'owner-package' && isInstanceOwner())
                        <section class="application-settings-section mb-6">
                            <div class="application-settings-section-body flex flex-col gap-4">
                                <div>
                                    <p class="text-sm font-medium">{{ __('Owner package') }}</p>
                                    <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                                        {{ __('Copy a branch of the house package into this project’s addon volume. Only the instance owner sees this. Client addons stay untouched.') }}
                                    </p>
                                </div>
                                @if ($ownerPackageRepository === '')
                                    <p class="text-sm text-neutral-500 dark:text-fg-dim">
                                        {{ __('Set the owner package repository in Settings → Odoo first.') }}
                                    </p>
                                @else
                                    <p class="text-sm">
                                        <span class="text-neutral-500 dark:text-fg-dim">{{ __('Repository') }}:</span>
                                        <span class="font-mono">{{ $ownerPackageRepository }}</span>
                                    </p>
                                    @if (filled($environment->odooBranch?->owner_package_branch))
                                        <p class="text-sm">
                                            <span class="text-neutral-500 dark:text-fg-dim">{{ __('Installed branch') }}:</span>
                                            <span class="font-mono">{{ $environment->odooBranch->owner_package_branch }}</span>
                                        </p>
                                    @endif
                                    <div class="flex max-w-xl items-end gap-2">
                                        <div class="min-w-0 flex-1">
                                            @if ($ownerPackageBranches !== [])
                                                <label class="mb-1.5 block text-sm font-medium" for="owner-package-branch">{{ __('Branch') }}</label>
                                                <select id="owner-package-branch" wire:model="ownerPackageBranch" class="input">
                                                    @foreach ($ownerPackageBranches as $branch)
                                                        <option value="{{ $branch }}">{{ $branch }}</option>
                                                    @endforeach
                                                </select>
                                            @else
                                                <x-forms.input id="ownerPackageBranch" label="{{ __('Branch') }}" placeholder="19.0" />
                                            @endif
                                        </div>
                                        <x-forms.button type="button" wire:click="loadOwnerPackageBranches">{{ __('Load branches') }}</x-forms.button>
                                    </div>
                                    <div>
                                        <x-forms.button type="button" wire:click="installOwnerPackage" canGate="update"
                                            :canResource="$service" isHighlighted
                                            wire:confirm="{{ __('Copy this owner package branch into the project volume and restart Odoo?') }}">
                                            {{ __('Install into project') }}
                                        </x-forms.button>
                                        <p wire:loading wire:target="installOwnerPackage" class="mt-2 text-[13px]">
                                            {{ __('Queuing the owner package install…') }}
                                        </p>
                                    </div>
                                @endif
                            </div>
                        </section>
                    @endif
                    @if (! $odooIsOdoo || $odooPanel === 'mounted')
                    <livewire:project.service.stack-form :service="$service" />

                    <div class="mt-8" x-data="{
                        viewMode: localStorage.getItem('service-compose-resources-view') || 'table',
                        setViewMode(mode) {
                            this.viewMode = mode;
                            localStorage.setItem('service-compose-resources-view', mode);
                        }
                    }">
                        <div class="mb-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 class="text-base font-semibold text-black dark:text-fg">{{ __('Compose resources') }}</h2>
                                <p class="mt-1 text-sm text-neutral-500 dark:text-fg-dim">
                                    {{ __('Applications and databases defined in this service.') }}
                                </p>
                            </div>
                            <div class="flex w-full items-center justify-between gap-2 sm:w-auto sm:justify-start">
                                <div
                                    class="view-toggle">
                                    <button type="button" x-on:click="setViewMode('table')"
                                        class="flex size-7.5 items-center justify-center rounded-md transition-colors"
                                        :class="viewMode === 'table'
                                            ? 'control-selected'
                                            : 'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg'"
                                        aria-label="{{ __('Table view') }}" title="{{ __('Table view') }}">
                                        <x-reicon name="unordered-list" class="size-3.5" />
                                    </button>
                                    <button type="button" x-on:click="setViewMode('grid')"
                                        class="flex size-7.5 items-center justify-center rounded-md transition-colors"
                                        :class="viewMode === 'grid'
                                            ? 'control-selected'
                                            : 'text-neutral-400 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg'"
                                        aria-label="{{ __('Grid view') }}" title="{{ __('Grid view') }}">
                                        <x-reicon name="grid" class="size-3.5" />
                                    </button>
                                </div>
                                <a class="button" target="_blank" href="{{ $service->documentation() }}">
                                    {{ __('Documentation') }}
                                    <x-reicon name="external-link" class="size-4" />
                                </a>
                            </div>
                        </div>

                        <div :class="viewMode === 'grid'
                            ? 'grid grid-cols-1 gap-3 sm:grid-cols-2'
                            : 'overflow-x-auto rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]'">
                            @if ($applications->isNotEmpty() || $databases->isNotEmpty())
                                <div x-cloak x-show="viewMode === 'table'"
                                    class="grid grid-cols-[minmax(0,1fr)_auto] gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_8rem_5rem] dark:border-white/[0.08] dark:bg-white/[0.025] dark:text-fg-faint">
                                    <div>{{ __('Resource') }}</div>
                                    <div class="hidden sm:block">{{ __('Image') }}</div>
                                    <div class="justify-self-start">{{ __('Status') }}</div>
                                    <div></div>
                                </div>
                            @endif

                            @if ($applications->isEmpty() && $databases->isEmpty())
                                <div
                                    class="application-settings-section overflow-hidden sm:col-span-2">
                                    <x-empty title="{{ __('No compose resources') }}"
                                        description="{{ __('No applications or databases are defined in this Docker Compose file.') }}"
                                        icon-name="grid" />
                                </div>
                            @endif

                            @foreach ($applications as $application)
                                <livewire:project.service.resource-card :service="$service" :resource="$application"
                                    :parameters="$parameters"
                                    wire:key="service-application-card-{{ $application->id }}" />
                            @endforeach
                            @foreach ($databases as $database)
                                <livewire:project.service.resource-card :service="$service" :resource="$database"
                                    :parameters="$parameters"
                                    wire:key="service-database-card-{{ $database->id }}" />
                            @endforeach
                        </div>
                    </div>
                    @endif
                @elseif ($currentRoute === 'project.service.domains')
                    <livewire:project.service.domains :service="$service" />
                @elseif ($currentRoute === 'project.service.environment-variables')
                    <livewire:project.shared.environment-variable.all :resource="$service" />
                    <livewire:project.shared.secret-manager-links :resource="$service" />
                @elseif ($currentRoute === 'project.service.storages')
                    <div class="space-y-6">
                        <div
                            class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] leading-5 text-amber-800 dark:border-warning/15 dark:bg-warning/[0.07] dark:text-amber-300/90">
                            {{ __('Service volume mounts are read-only here. Edit the Docker Compose file and reload it to change volumes.') }}
                        </div>
                        @foreach ($applications as $application)
                            <livewire:project.service.storage wire:key="application-{{ $application->id }}"
                                :resource="$application" />
                        @endforeach
                        @foreach ($databases as $database)
                            <livewire:project.service.storage wire:key="database-{{ $database->id }}"
                                :resource="$database" />
                        @endforeach
                    </div>
                @elseif ($currentRoute === 'project.service.scheduled-tasks.show')
                    <livewire:project.shared.scheduled-task.all :resource="$service" />
                @elseif ($currentRoute === 'project.service.scheduled-tasks')
                    <livewire:project.shared.scheduled-task.show />
                @elseif ($currentRoute === 'project.service.webhooks')
                    <livewire:project.shared.webhooks :resource="$service" />
                @elseif ($currentRoute === 'project.service.resource-operations')
                    <livewire:project.shared.resource-operations :resource="$service" />
                @elseif ($currentRoute === 'project.service.tags')
                    <livewire:project.shared.tags :resource="$service" />
                @elseif ($currentRoute === 'project.service.danger')
                    <livewire:project.shared.danger :resource="$service" />
                @endif
            </div>
        </div>
    </section>
</div>
