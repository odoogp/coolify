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
            ['label' => __('General'), 'route' => 'project.service.configuration', 'icon' => 'settings'],
            ['label' => __('Domains'), 'route' => 'project.service.domains', 'icon' => 'globe'],
            ['label' => __('Environment Variables'), 'route' => 'project.service.environment-variables', 'icon' => 'variables', 'hasWarning' => ! $service->isDeployable],
            ['label' => __('Persistent Storage'), 'route' => 'project.service.storages', 'icon' => 'storages'],
            ['label' => __('Backups'), 'route' => 'project.service.volume-backups.index', 'icon' => 'database'],
            ['label' => __('Runtime Logs'), 'route' => 'project.service.logs', 'icon' => 'unordered-list', 'navigate' => false],
            ['label' => __('Terminal'), 'route' => 'project.service.command', 'icon' => 'browser-terminal', 'navigate' => false, 'visible' => auth()->user()?->canOpenTerminal($service)],
            ['label' => __('Scheduled Tasks'), 'route' => 'project.service.scheduled-tasks.show', 'icon' => 'calendar'],
            ['label' => __('Webhooks'), 'route' => 'project.service.webhooks', 'icon' => 'notifications'],
            ['label' => __('Resource Operations'), 'route' => 'project.service.resource-operations', 'icon' => 'server-update'],
            ['label' => __('Tags'), 'route' => 'project.service.tags', 'icon' => 'tags'],
            ['label' => __('Danger Zone'), 'route' => 'project.service.danger', 'icon' => 'shield-alert'],
        ])->filter(fn (array $item): bool => $item['visible'] ?? true)
            ->when($clientOdooNav, fn ($items) => $items->filter(fn (array $item): bool => in_array($item['route'], [
                'project.service.configuration',
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
                'Settings' => ['General'],
                'Observe & troubleshoot' => ['Runtime Logs', 'Terminal'],
                'Operations' => ['Danger Zone'],
            ]
            : [
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
                        @unless ($loop->first)
                            <div class="my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]"
                                aria-hidden="true"></div>
                        @endunless
                        <div class="nav-section hidden xl:block">{{ $groupLabel }}</div>
                        @foreach ($groupItems as $menuItem)
                            <a @class([
                                'menu-item',
                                'menu-item-active' => $menuItem['active'],
                            ])
                                @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                                href="{{ route($menuItem['route'], $serviceRouteParameters) }}">
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
                    @endforeach
                </nav>
            </aside>

            <div class="min-w-0">
                @if ($currentRoute === 'project.service.configuration')
                    @if ($odooIsOdoo && $odooPanel === 'github')
                        <section class="application-settings-section mb-6">
                            <div class="application-settings-section-body flex flex-col gap-4">
                                <div>
                                    <p class="text-sm font-medium">{{ __('GitHub') }}</p>
                                    <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                                        {{ __('The environment name and the GitHub branch are stored separately. A push updates the addon folder.') }}
                                    </p>
                                </div>
                                @if (filled($project->odooProfile?->git_repository))
                                    @php
                                        $githubBranch = (string) ($environment->odooBranch?->git_branch ?? '');
                                        $odooGithubUrl = \App\Support\OdooGit::repositoryUrl(
                                            (string) $project->odooProfile->git_repository,
                                            $githubBranch,
                                        );
                                    @endphp
                                    <p class="font-mono text-[13px]">{{ $project->odooProfile->git_repository }}</p>
                                    <p class="text-[13px]">{{ __('Environment :name.', ['name' => $environment->name]) }}</p>
                                    @if ($githubBranch !== '')
                                        <p class="text-[13px]">{{ __('GitHub branch :branch.', ['branch' => $githubBranch]) }}</p>
                                    @endif
                                    @if ($odooGithubUrl !== '')
                                        <a class="button w-fit" href="{{ $odooGithubUrl }}" target="_blank" rel="noopener noreferrer">
                                            {{ __('Open on GitHub') }}
                                        </a>
                                    @endif
                                    @if ($odooGithubConnected && ! $odooAccountChanged)
                                        @if ($odooGithubBranches === [])
                                            <x-forms.button type="button" wire:click="loadLinkedOdooBranches" canGate="update" :canResource="$service">
                                                {{ __('Change branch') }}
                                            </x-forms.button>
                                        @else
                                            <div class="max-w-sm space-y-2">
                                                <x-forms.searchable-listbox id="odooBranch" label="{{ __('GitHub branch') }}"
                                                    searchPlaceholder="{{ __('Search branches') }}"
                                                    emptyText="{{ __('No matching branch') }}"
                                                    :options="collect($odooGithubBranches)->map(fn (string $branch): array => ['value' => $branch, 'label' => $branch])->all()" />
                                                <x-forms.button type="button" wire:click="saveLinkedOdooBranch" canGate="update" :canResource="$service">
                                                    {{ __('Save GitHub branch') }}
                                                </x-forms.button>
                                            </div>
                                        @endif
                                    @endif
                                @endif
                                @if (! $odooGithubConnected)
                                    <div>
                                        <x-forms.button type="button" wire:click="connectOdooGithub" canGate="update" :canResource="$service">
                                            {{ __('Connect GitHub') }}
                                        </x-forms.button>
                                    </div>
                                @else
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <p class="text-[13px]">
                                            {{ __('GitHub account :login, linked to :user.', [
                                                'login' => $odooGithubLogin !== '' ? $odooGithubLogin : __('this account'),
                                                'user' => auth()->user()->email,
                                            ]) }}
                                        </p>
                                        <x-forms.button type="button" wire:click="connectOdooGithub" canGate="update" :canResource="$service">
                                            {{ __('Change account') }}
                                        </x-forms.button>
                                    </div>
                                    @if (blank($project->odooProfile?->git_repository) || $odooAccountChanged)
                                    @if ($odooAccountChanged)
                                        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                            {{ __('This GitHub account is not the one linked to the repository. Associate a new repository, or choose an existing repository and a branch.') }}
                                        </p>
                                    @elseif ($associatingWithoutGithub)
                                        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                            {{ __('This project started without GitHub. Create a repository and push the custom addons that are already on the instance.') }}
                                        </p>
                                    @else
                                        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                            {{ $awaitingRepositoryChoice
                                                ? __('GitHub is connected. Launch production on a new repository, or search an existing one.')
                                                : __('Choose the GitHub branch for this environment. It can differ from the environment name.') }}
                                        </p>
                                    @endif
                                    @unless ($associatingWithoutGithub)
                                    <div class="flex flex-wrap gap-4 text-sm">
                                        <label class="inline-flex items-center gap-2">
                                            <input type="radio" wire:model.live="odooRepoMode" value="new" class="rounded-full">
                                            {{ __('Launch production on a new repository') }}
                                        </label>
                                        <label class="inline-flex items-center gap-2">
                                            <input type="radio" wire:model.live="odooRepoMode" value="existing" class="rounded-full">
                                            {{ __('Use an existing repository') }}
                                        </label>
                                    </div>
                                    @endunless
                                    @if ($odooRepoMode === 'new' || $associatingWithoutGithub)
                                        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                            @if (strcasecmp($environment->name, 'production') === 0)
                                                {{ __('The new repository is named :name. This environment tracks its default branch.', ['name' => \App\Support\OdooGit::repositoryName($project)]) }}
                                            @else
                                                {{ __('The new repository is named :name. This environment gets the branch :branch.', ['name' => \App\Support\OdooGit::repositoryName($project), 'branch' => $environment->name]) }}
                                            @endif
                                        </p>
                                    @else
                                        <div class="max-w-sm space-y-2">
                                            <div class="flex items-end gap-2">
                                                <div class="min-w-0 flex-1">
                                                    <label class="mb-1.5 block text-sm font-medium" for="odoo-repository-query">{{ __('Repository') }}</label>
                                                    <input id="odoo-repository-query" type="search" wire:model.live.debounce.200ms="odooRepositoryQuery"
                                                        placeholder="{{ __('Search repositories') }}" class="input">
                                                </div>
                                                <x-forms.button type="button" wire:click="reloadOdooRepositories" canGate="update" :canResource="$service">
                                                    {{ __('Refresh repositories') }}
                                                </x-forms.button>
                                            </div>
                                            <p wire:loading wire:target="reloadOdooRepositories,odooRepoMode" class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                                {{ __('Loading repositories…') }}
                                            </p>
                                            @php
                                                $repositoryQuery = strtolower($odooRepositoryQuery);
                                                $repositoryMatches = collect($odooRepositories)
                                                    ->filter(fn (array $repository): bool => $repositoryQuery === '' || str_contains(strtolower($repository['full_name']), $repositoryQuery))
                                                    ->take(20);
                                            @endphp
                                            @if ($repositoryMatches->isNotEmpty())
                                                <ul class="overflow-hidden rounded-lg border border-neutral-200 dark:border-white/[0.08]">
                                                    @foreach ($repositoryMatches as $repository)
                                                        <li>
                                                            <button type="button" wire:click="pickOdooRepository({{ $repository['id'] }})"
                                                                class="flex w-full items-center px-3 py-2 text-left font-mono text-[13px] hover:bg-neutral-100 dark:hover:bg-white/[0.06] {{ (int) $odooRepositoryId === (int) $repository['id'] ? 'bg-neutral-100 dark:bg-white/[0.06]' : '' }}">
                                                                {{ $repository['full_name'] }}
                                                            </button>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @elseif ($odooRepositoriesLoaded)
                                                <p class="text-[13px] text-neutral-500 dark:text-fg-dim">{{ __('No matching repositories.') }}</p>
                                            @endif
                                            @if ($odooGithubBranches !== [])
                                                <x-forms.searchable-listbox id="odooBranch" label="{{ __('GitHub branch') }}"
                                                    searchPlaceholder="{{ __('Search branches') }}"
                                                    emptyText="{{ __('No matching branch') }}"
                                                    :options="collect($odooGithubBranches)->map(fn (string $branch): array => ['value' => $branch, 'label' => $branch])->all()" />
                                                @if (filled($environment->odooBranch?->git_branch) && ! in_array((string) $environment->odooBranch->git_branch, $odooGithubBranches, true))
                                                    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                                        {{ __('The branch :branch is not on this repository. Choose another branch.', ['branch' => $environment->odooBranch->git_branch]) }}
                                                    </p>
                                                @endif
                                            @endif
                                        </div>
                                    @endif
                                    <div class="flex flex-wrap items-center gap-3">
                                        <x-forms.button type="button" wire:click="associateOdooRepository" canGate="update" :canResource="$service" isHighlighted>
                                            {{ $associatingWithoutGithub
                                                ? __('Create repository')
                                                : ($odooRepoMode === 'new' ? __('Launch production') : __('Associate repository')) }}
                                        </x-forms.button>
                                        @unless ($associatingWithoutGithub)
                                        <x-forms.button type="button" wire:click="launchWithoutGithub" canGate="update" :canResource="$service">
                                            {{ __('Launch without GitHub') }}
                                        </x-forms.button>
                                        @endunless
                                        <p wire:loading wire:target="associateOdooRepository" class="text-[13px]">
                                            {{ $associatingWithoutGithub ? __('Creating the repository…') : __('Starting Odoo.') }}
                                        </p>
                                    </div>
                                    @endif
                                @endif
                            </div>
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
                                    class="flex h-9 items-center rounded-lg border border-neutral-200 bg-white p-0.5 dark:border-white/[0.08] dark:bg-white/[0.06]">
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
                            : 'overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.025]'">
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
