@props(['application', 'currentRoute', 'flush' => false])

@php
        $applicationRouteParameters = [
            'project_uuid' => $application->environment->project->uuid,
            'environment_uuid' => $application->environment->uuid,
            'application_uuid' => $application->uuid,
        ];

        $configurationMenuItems = [
            [
                'label' => __('General'),
                'route' => 'project.application.configuration',
                'active' => $currentRoute === 'project.application.configuration',
            ],
            [
                'label' => __('Domains'),
                'route' => 'project.application.domains',
                'active' => $currentRoute === 'project.application.domains',
            ],
            [
                'label' => __('Advanced'),
                'route' => 'project.application.advanced',
                'active' => $currentRoute === 'project.application.advanced',
            ],
            [
                'label' => __('Swarm'),
                'route' => 'project.application.swarm',
                'active' => $currentRoute === 'project.application.swarm',
                'visible' => $application->destination->server->isSwarm(),
            ],
            [
                'label' => __('Environment Variables'),
                'route' => 'project.application.environment-variables',
                'active' => $currentRoute === 'project.application.environment-variables',
            ],
            [
                'label' => __('Persistent Storage'),
                'route' => 'project.application.persistent-storage',
                'active' => $currentRoute === 'project.application.persistent-storage',
            ],
            [
                'label' => __('Backups'),
                'route' => 'project.application.backup.index',
                'active' => str($currentRoute)->startsWith('project.application.backup'),
            ],
            [
                'label' => __('Terminal'),
                'route' => 'project.application.command',
                'active' => $currentRoute === 'project.application.command',
                'navigate' => false,
                'visible' => ! $application->destination->server->isSwarm() && auth()->user()?->canOpenTerminal($application),
            ],
            [
                'label' => __('Deployment Logs'),
                'route' => 'project.application.deployment.index',
                'active' => str($currentRoute)->startsWith('project.application.deployment'),
            ],
            [
                'label' => __('Runtime Logs'),
                'route' => 'project.application.logs',
                'active' => $currentRoute === 'project.application.logs',
            ],
            [
                'label' => __('Git Source'),
                'route' => 'project.application.source',
                'active' => $currentRoute === 'project.application.source',
                'visible' => $application->git_based(),
            ],
            [
                'label' => __('Servers'),
                'route' => 'project.application.servers',
                'active' => $currentRoute === 'project.application.servers',
                'badge' => true,
            ],
            [
                'label' => __('Scheduled Tasks'),
                'route' => 'project.application.scheduled-tasks.show',
                'active' => str($currentRoute)->startsWith('project.application.scheduled-tasks'),
            ],
            [
                'label' => __('Webhooks'),
                'route' => 'project.application.webhooks',
                'active' => $currentRoute === 'project.application.webhooks',
            ],
            [
                'label' => __('Preview Deployments'),
                'route' => 'project.application.preview-deployments',
                'active' => $currentRoute === 'project.application.preview-deployments',
                'visible' => $application->git_based() || $application->build_pack === 'dockerimage',
            ],
            [
                'label' => __('Healthcheck'),
                'route' => 'project.application.healthcheck',
                'active' => $currentRoute === 'project.application.healthcheck',
                'visible' => $application->build_pack !== 'dockercompose',
            ],
            [
                'label' => __('Rollback'),
                'route' => 'project.application.rollback',
                'active' => $currentRoute === 'project.application.rollback',
            ],
            [
                'label' => __('Resource Limits'),
                'route' => 'project.application.resource-limits',
                'active' => $currentRoute === 'project.application.resource-limits',
            ],
            [
                'label' => __('Resource Operations'),
                'route' => 'project.application.resource-operations',
                'active' => $currentRoute === 'project.application.resource-operations',
            ],
            [
                'label' => __('Metrics'),
                'route' => 'project.application.metrics',
                'active' => $currentRoute === 'project.application.metrics',
            ],
            [
                'label' => __('Tags'),
                'route' => 'project.application.tags',
                'active' => $currentRoute === 'project.application.tags',
            ],
            [
                'label' => __('Danger Zone'),
                'route' => 'project.application.danger',
                'active' => $currentRoute === 'project.application.danger',
            ],
        ];

        $configurationMenuItems = array_values(array_filter(
            $configurationMenuItems,
            fn (array $item): bool => $item['visible'] ?? true,
        ));

        // Icons follow the main sidebar's reicon set
        $menuIcons = [
            'General' => 'settings',
            'Domains' => 'globe',
            'Advanced' => 'grid',
            'Swarm' => 'destinations',
            'Environment Variables' => 'variables',
            'Persistent Storage' => 'storages',
            'Backups' => 'database',
            'Terminal' => 'browser-terminal',
            'Deployment Logs' => 'time-back',
            'Runtime Logs' => 'unordered-list',
            'Git Source' => 'sources',
            'Servers' => 'servers',
            'Scheduled Tasks' => 'calendar',
            'Webhooks' => 'notifications',
            'Preview Deployments' => 'eye',
            'Healthcheck' => 'feedback',
            'Rollback' => 'time-back',
            'Resource Limits' => 'cpu',
            'Resource Operations' => 'server-update',
            'Metrics' => 'graph',
            'Analytics' => 'analytics',
            'Tags' => 'tags',
            'Danger Zone' => 'shield-alert',
        ];

        // Discord-style groups for the settings sidebar
        $menuGroups = [
            'Settings' => ['General', 'Domains', 'Environment Variables', 'Persistent Storage', 'Advanced', 'Swarm', 'Healthcheck'],
            'Observe & troubleshoot' => ['Runtime Logs', 'Deployment Logs', 'Terminal', 'Metrics', 'Analytics'],
            'Deploy' => ['Git Source', 'Servers', 'Preview Deployments'],
            'Automation' => ['Scheduled Tasks', 'Webhooks', 'Backups'],
            'Operations' => ['Resource Operations', 'Resource Limits', 'Rollback', 'Tags', 'Danger Zone'],
        ];
        $groupedMenuItems = collect($menuGroups)
            ->map(fn (array $labels) => collect($labels)
                ->map(fn (string $label) => collect($configurationMenuItems)->firstWhere('label', $label))
                ->filter()
                ->values())
            ->filter(fn ($items) => $items->isNotEmpty());

        // Group that holds the current page — always kept open, even if collapsed before.
        $activeGroup = (string) $groupedMenuItems->search(fn ($items) => $items->contains(fn ($item) => $item['active'] ?? false));

        // In-page sections (cards) shown as sub-items under the active page
        $isComposeApp = $application->build_pack === 'dockercompose';
        $pageSections = [
            'project.application.configuration' => array_values(array_filter([
                ['id' => 'application-details-section', 'label' => __('Application details')],
                ['id' => 'access-section', 'label' => __('Access')],
                ['id' => 'build-pipeline-section', 'label' => __('Build pipeline')],
                $isComposeApp ? null : ['id' => 'container-image-section', 'label' => __('Container image')],
                $isComposeApp ? null : ['id' => 'networking-section', 'label' => __('Networking')],
                $isComposeApp ? null : ['id' => 'runtime-section', 'label' => __('Runtime')],
                $isComposeApp ? null : ['id' => 'security-section', 'label' => __('Security')],
                ['id' => 'deployment-lifecycle-section', 'label' => __('Deployment lifecycle')],
                $isComposeApp ? null : ['id' => 'container-labels-section', 'label' => __('Container labels')],
            ])),
            'project.application.advanced' => array_values(array_filter([
                ['id' => 'advanced-build-section', 'label' => __('Build')],
                ['id' => 'advanced-container-section', 'label' => __('Container')],
                $application->git_based() ? ['id' => 'advanced-deployment-section', 'label' => __('Deployment')] : null,
                $application->git_based() ? ['id' => 'advanced-git-section', 'label' => __('Git')] : null,
                $isComposeApp ? ['id' => 'advanced-compose-section', 'label' => __('Docker compose')] : null,
                ['id' => 'advanced-proxy-section', 'label' => __('Proxy')],
                ['id' => 'advanced-operations-section', 'label' => __('Operations')],
                ['id' => 'advanced-logs-section', 'label' => __('Logs')],
                $isComposeApp ? null : ['id' => 'advanced-gpu-section', 'label' => __('GPU')],
            ])),
            'project.application.webhooks' => [
                ['id' => 'deploy-webhook-section', 'label' => __('Deploy webhook')],
                ['id' => 'manual-git-webhooks-section', 'label' => __('Manual Git webhooks')],
            ],
            'project.application.preview-deployments' => array_values(array_filter([
                ['id' => 'preview-template-section', 'label' => __('URL template')],
                $application->is_github_based()
                    ? ['id' => 'preview-pull-requests-section', 'label' => __('Pull requests')]
                    : null,
                $application->build_pack === 'dockerimage'
                    ? ['id' => 'manual-preview-section', 'label' => __('Manual preview')]
                    : null,
                ['id' => 'preview-deployments-section', 'label' => __('Deployments')],
            ])),
            'project.application.healthcheck' => [
                ['id' => 'healthcheck-configuration-section', 'label' => __('Configuration')],
                [
                    'id' => $application->health_check_type === 'cmd'
                        ? 'healthcheck-command-section'
                        : 'healthcheck-request-section',
                    'label' => $application->health_check_type === 'cmd' ? 'Command' : 'HTTP request',
                ],
                ['id' => 'healthcheck-timing-section', 'label' => __('Timing and retries')],
            ],
            'project.application.rollback' => [
                ['id' => 'rollback-retention-section', 'label' => __('Image retention')],
                ['id' => 'rollback-images-section', 'label' => __('Available images')],
            ],
            'project.application.resource-limits' => [
                ['id' => 'cpu-limits-section', 'label' => __('CPU')],
                ['id' => 'memory-limits-section', 'label' => __('Memory')],
            ],
            'project.application.resource-operations' => [
                ['id' => 'clone-destination-section', 'label' => __('Clone destination')],
                ['id' => 'clone-environment-section', 'label' => __('Clone environment')],
                ['id' => 'move-resource-section', 'label' => __('Move resource')],
            ],
        ];

        // Flat, searchable index: every page plus its in-page sub-sections. Each
        // entry carries a breadcrumb (its category, and parent page for a
        // sub-section) and combined text so the query matches sub-pages too.
        $searchIndex = [];
        foreach ($groupedMenuItems as $groupLabel => $groupItems) {
            foreach ($groupItems as $item) {
                $searchIndex[] = [
                    'label' => $item['label'],
                    'breadcrumb' => $groupLabel,
                    'searchText' => $item['label'].' '.$groupLabel,
                    'href' => route($item['route'], $applicationRouteParameters),
                    'icon' => $menuIcons[$item['label']] ?? 'settings',
                    'navigate' => $item['navigate'] ?? true,
                ];
                foreach ($pageSections[$item['route']] ?? [] as $section) {
                    $searchIndex[] = [
                        'label' => $section['label'],
                        'breadcrumb' => $groupLabel.' · '.$item['label'],
                        'searchText' => $section['label'].' '.$item['label'].' '.$groupLabel,
                        'href' => route($item['route'], $applicationRouteParameters).'#'.$section['id'],
                        'icon' => $menuIcons[$item['label']] ?? 'settings',
                        'navigate' => true,
                    ];
                }
            }
        }
        $searchTexts = array_column($searchIndex, 'searchText');
    @endphp

<aside @class([
    'application-settings-navigation min-w-0 xl:self-start',
    'is-flush' => $flush,
])>
                <nav aria-label="{{ __('Configuration sections') }}"
                    class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
                    {{-- A quiet inline filter, deliberately lighter than the global ⌘K
                         search so the two don't read as duplicate search bars. --}}
                    <div class="relative col-span-full mb-1.5 xl:mb-2">
                        <x-reicon name="search"
                            class="pointer-events-none absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                        <input x-model.debounce.100ms="search" type="search" placeholder="Filter settings"
                            aria-label="Filter settings"
                            class="h-7 w-full rounded-md border-0 bg-black/[0.035] py-0 pr-7 pl-7 text-[12px] text-nav-text shadow-none outline-none ring-0 transition-colors placeholder:text-neutral-400 focus:bg-black/[0.05] focus-visible:ring-1 focus-visible:ring-accent/40 dark:bg-white/[0.04] dark:text-fg dark:placeholder:text-fg-faint dark:focus:bg-white/[0.06]">
                        <button x-cloak x-show="searching" type="button" @click="search = ''" aria-label="Clear filter"
                            class="absolute top-1/2 right-1 flex size-5 -translate-y-1/2 items-center justify-center rounded text-neutral-400 transition-colors hover:text-black dark:text-fg-faint dark:hover:text-fg">
                            <x-reicon name="x" class="size-3" />
                        </button>
                    </div>
                    <p x-cloak x-show="searching && !hasResults"
                        class="col-span-full px-2.5 py-2 text-[12px] text-neutral-500 dark:text-fg-dim">
                        No settings match “<span x-text="search"></span>”.
                    </p>

                    {{-- Flat search results (pages + sub-sections), each with its category/parent. --}}
                    <div x-cloak x-show="searching" class="col-span-full flex flex-col gap-0.5">
                        @foreach ($searchIndex as $entry)
                            <a href="{{ $entry['href'] }}" @if ($entry['navigate']) {{ wireNavigate() }} @endif
                                x-show="matches(@js($entry['searchText']))"
                                class="group flex flex-col gap-0.5 rounded-md px-2.5 py-1.5 transition-colors hover:bg-black/[0.04] dark:hover:bg-white/[0.05]">
                                <span class="flex min-w-0 items-center gap-2.5">
                                    <x-reicon :name="$entry['icon']" class="size-[18px] shrink-0 text-nav-text opacity-90" />
                                    <span class="truncate text-[13px] font-medium text-nav-text">{{ $entry['label'] }}</span>
                                </span>
                                <span class="truncate pl-[28px] text-[11px] text-neutral-400 dark:text-fg-faint">{{ $entry['breadcrumb'] }}</span>
                            </a>
                        @endforeach
                    </div>

                    @foreach ($groupedMenuItems as $groupLabel => $groupItems)
                        @unless ($loop->first)
                            <div class="my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]"
                                x-show="!searching" aria-hidden="true"></div>
                        @endunless
                        <button type="button" class="nav-section-toggle hidden xl:flex" x-show="!searching"
                            @click="toggle(@js($groupLabel))" :aria-expanded="isOpen(@js($groupLabel))">
                            <span>{{ $groupLabel }}</span>
                            <svg class="size-3 shrink-0 opacity-60 transition-transform"
                                :class="!isOpen(@js($groupLabel)) && '-rotate-90'" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6" />
                            </svg>
                        </button>
                        <div class="contents" :class="isOpen(@js($groupLabel)) ? 'xl:block' : 'xl:hidden'">
                        @foreach ($groupItems as $menuItem)
                            @php $sections = $pageSections[$menuItem['route']] ?? []; @endphp
                            <div wire:key="application-settings-group-{{ str($menuItem['label'])->slug() }}"
                                x-show="!searching">
                                <a wire:key="application-settings-link-{{ str($menuItem['label'])->slug() }}"
                                    @class([
                                        'menu-item',
                                        'menu-item-active' => $menuItem['active'],
                                    ])
                            @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                                    href="{{ route($menuItem['route'], $applicationRouteParameters) }}"
                                    >
                                    <x-reicon :name="$menuIcons[$menuItem['label']] ?? 'settings'" class="menu-item-icon" />
                                    <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                                    @if ($menuItem['badge'] ?? false)
                                        <span class="shrink-0">
                                            <livewire:project.application.server-status-badge :application="$application"
                                                :key="'application-server-status-'.$application->uuid" />
                                        </span>
                                    @endif
                                </a>
                                {{-- Sub-sections belong to the current page only; collapse them for
                                     every other item so the sidebar stays short. --}}
                                @if ($menuItem['active'] && filled($sections))
                                    <div class="nav-children hidden flex-col gap-0.5 py-1 xl:flex" x-show="!searching"
                                        x-data="{ activeSection: '' }">
                                        @foreach ($sections as $section)
                                            <button type="button" class="menu-subitem"
                                                :class="activeSection === '{{ $section['id'] }}' && 'menu-subitem-active'"
                                                x-on:click="activeSection = '{{ $section['id'] }}'; history.replaceState(null, '', '#{{ $section['id'] }}'); window.scrollToSettingsSection?.('{{ $section['id'] }}')">
                                                <span class="menu-item-label text-left">{{ $section['label'] }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                        </div>
                    @endforeach
                </nav>
            </aside>
