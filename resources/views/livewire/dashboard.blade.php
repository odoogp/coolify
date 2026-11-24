<div class="application-settings-form w-full">
    <x-slot:title>
        {{ __('Dashboard | Coolify') }}
    </x-slot>

    @if (session('error'))
        <span x-data x-init="$wire.dispatch('error', @js(session('error')))" />
    @endif

    @php
        $dashboardItemLimit = 3;
        $dashboardProjects = $projects->sortBy('name', SORT_NATURAL)->take($dashboardItemLimit);
        $dashboardServers = $servers->sortBy('name', SORT_NATURAL)->take($dashboardItemLimit);
    @endphp

    @php
        $runningRows = $resourceRows->where('state', \App\Support\ResourceOverview::RUNNING);
        $attentionRows = $resourceRows->whereIn('state', [\App\Support\ResourceOverview::STOPPED, \App\Support\ResourceOverview::ATTENTION, \App\Support\ResourceOverview::STARTING])->values();
        $stoppedCount = $resourceRows->where('state', \App\Support\ResourceOverview::STOPPED)->count();
        $readyServers = $servers->filter(fn ($server) => (bool) data_get($server, 'settings.is_reachable') && (bool) data_get($server, 'settings.is_usable'))->count();
        $stateDot = [
            'running' => 'bg-emerald-500',
            'stopped' => 'bg-amber-400',
            'starting' => 'bg-sky-400 animate-pulse',
            'attention' => 'bg-red-500',
        ];
        $stateLabel = [
            'running' => __('Running'),
            'stopped' => __('Stopped'),
            'starting' => __('Starting'),
            'attention' => __('Needs a look'),
        ];
        $hour = (int) now()->timezone(auth()->user()?->timezone ?? config('app.timezone'))->format('G');
        $greeting = $hour < 12 ? __('Good morning') : ($hour < 19 ? __('Good afternoon') : __('Good evening'));
    @endphp

    <div class="flex min-w-0 flex-col gap-8" data-dashboard-overview>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-2xl! leading-tight! font-semibold! tracking-tight text-black dark:text-fg">
                    {{ $greeting }}, {{ str(auth()->user()?->name)->before(' ') }}
                </h1>
                <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-faint">
                    @if ($stoppedCount > 0)
                        {{ trans_choice(':count resource needs attention. Everything else is fine.|:count resources need attention. Everything else is fine.', $stoppedCount, ['count' => $stoppedCount]) }}
                    @elseif ($resourceRows->isEmpty())
                        {{ __('Launch your first app to see it here.') }}
                    @else
                        {{ __('Everything is running.') }}
                    @endif
                </p>
            </div>
            @can('createAnyResource')
                <button type="button" class="button button-highlighted" @click="$dispatch('open-global-search', { query: 'new' })">
                    <x-reicon name="plus" class="size-3.5" />
                    {{ __('New resource') }}
                </button>
            @endcan
        </div>

        @if ($resourceRows->isNotEmpty() || $servers->isNotEmpty())
            <section class="mb-0! grid min-w-0 grid-cols-1 overflow-hidden rounded-xl border border-neutral-200 bg-white sm:grid-cols-3 dark:border-white/[0.08] dark:bg-white/[0.025]"
                aria-label="{{ __('Status') }}" data-status-strip>
                <div class="flex min-w-0 flex-col gap-1 px-4 py-3.5">
                    <span class="flex items-center gap-1.5 text-[12px] text-neutral-500 dark:text-fg-faint">
                        <x-reicon name="servers" class="size-3.5" />{{ __('Servers') }}
                    </span>
                    <span class="text-xl font-semibold tabular-nums text-black dark:text-fg">{{ $readyServers }}<span class="text-[13px] font-medium text-neutral-400 dark:text-fg-faint"> / {{ $servers->count() }} {{ __('ready') }}</span></span>
                </div>
                <div class="flex min-w-0 flex-col gap-1 border-t border-neutral-200 px-4 py-3.5 sm:border-t-0 sm:border-l dark:border-white/[0.08]">
                    <span class="flex items-center gap-1.5 text-[12px] text-neutral-500 dark:text-fg-faint">
                        <span class="size-1.5 rounded-full bg-emerald-500"></span>{{ __('Running') }}
                    </span>
                    <span class="text-xl font-semibold tabular-nums text-black dark:text-fg">{{ $runningRows->count() }}<span class="text-[13px] font-medium text-neutral-400 dark:text-fg-faint"> {{ __('of') }} {{ $resourceRows->count() }}</span></span>
                </div>
                <div @class([
                    'flex min-w-0 flex-col gap-1 border-t border-neutral-200 px-4 py-3.5 sm:border-t-0 sm:border-l dark:border-white/[0.08]',
                    'bg-amber-400/[0.08]' => $stoppedCount > 0,
                ])>
                    <span class="flex items-center gap-1.5 text-[12px] text-neutral-500 dark:text-fg-faint">
                        <span class="size-1.5 rounded-full bg-amber-400"></span>{{ __('Stopped') }}
                    </span>
                    <span @class(['text-xl font-semibold tabular-nums', 'text-amber-600 dark:text-amber-300' => $stoppedCount > 0, 'text-black dark:text-fg' => $stoppedCount === 0])>{{ $stoppedCount }}</span>
                </div>
            </section>
        @endif

        @if ($attentionRows->isNotEmpty())
            <section class="mb-0! min-w-0" data-needs-attention>
                <h2 class="mb-3 text-[15px]! font-semibold! text-black dark:text-fg">{{ __('Needs attention') }}</h2>
                <div class="divide-y divide-neutral-200 overflow-hidden rounded-xl border border-neutral-200 bg-white dark:divide-white/[0.08] dark:border-white/[0.08] dark:bg-white/[0.025]">
                    @foreach ($attentionRows->take(6) as $row)
                        <div class="flex min-w-0 items-center gap-3 px-4 py-2.5" wire:key="attention-{{ $row['uuid'] }}">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-neutral-100 text-neutral-500 dark:bg-white/[0.05] dark:text-fg-dim">
                                <x-reicon name="{{ $row['kind'] === 'service' ? 'projects' : 'servers' }}" class="size-4" />
                            </span>
                            <a href="{{ $row['url'] }}" {{ wireNavigate() }} class="min-w-0 flex-1 hover:no-underline">
                                <span class="block truncate text-[13px] font-medium text-black dark:text-fg" title="{{ $row['raw_name'] }}">{{ $row['name'] }}</span>
                                <span class="block truncate text-[12px] text-neutral-500 dark:text-fg-faint">{{ $row['project'] }}, {{ $row['environment'] }}</span>
                            </a>
                            <span class="hidden shrink-0 items-center gap-1.5 text-[12px] text-neutral-600 sm:inline-flex dark:text-fg-dim">
                                <span class="size-1.5 rounded-full {{ $stateDot[$row['state']] }}"></span>{{ $stateLabel[$row['state']] }}
                            </span>
                            @if ($row['state'] === 'stopped')
                                <button type="button" class="button shrink-0" wire:click="startResource('{{ $row['kind'] }}', '{{ $row['uuid'] }}')"
                                    wire:loading.attr="disabled" wire:target="startResource('{{ $row['kind'] }}', '{{ $row['uuid'] }}')">
                                    <x-reicon name="play-circle" class="size-3.5" />
                                    {{ __('Start') }}
                                </button>
                            @else
                                <a href="{{ $row['url'] }}" {{ wireNavigate() }} class="button shrink-0">{{ __('Open') }}</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <livewire:dashboard.active-deployments />

        <section class="mb-0! min-w-0">
            <div class="mb-3 flex items-end justify-between gap-4">
                <div>
                    <h2 class="text-xl! leading-6! font-semibold! tracking-tight text-black dark:text-fg">
                        {{ __('Projects') }}
                    </h2>
                    <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-faint">
                        {{ __('Your deployment workspaces') }}
                    </p>
                </div>
                <a href="{{ route('project.index') }}" {{ wireNavigate() }}
                    class="inline-flex shrink-0 items-center gap-1 text-[12px] font-medium text-neutral-500 transition-colors hover:text-black dark:text-fg-dim dark:hover:text-fg">
                    {{ __('View all') }}
                    <x-reicon name="arrow-right" class="size-3" />
                </a>
            </div>

            @if ($dashboardProjects->isEmpty())
                <x-empty title="{{ __('No projects yet') }}"
                    description="{{ __('Use New to create your first deployment workspace.') }}"
                    icon-name="projects" size="sm" />
            @else
                <div class="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($dashboardProjects as $project)
                        @php
                            $firstEnvironment = $project->environments->first();
                            $resourceCount = collect([
                                $project->applications_count,
                                $project->services_count,
                                $project->postgresqls_count,
                                $project->redis_count,
                                $project->keydbs_count,
                                $project->dragonflies_count,
                                $project->clickhouses_count,
                                $project->mongodbs_count,
                                $project->mysqls_count,
                                $project->mariadbs_count,
                            ])->sum();
                        @endphp

                        <article
                            class="feature-tile group relative flex min-w-0 flex-col rounded-xl border border-neutral-200 bg-white p-4 shadow-sm motion-tile hover:border-neutral-300 hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                            <a href="{{ $project->navigateTo() }}" {{ wireNavigate() }}
                                class="absolute inset-0 rounded-xl"
                                aria-label="Open {{ $project->name }}"></a>

                            <div class="flex min-w-0 items-start gap-3">
                                <div
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                    @if ($project->icon_path)
                                        <img src="{{ route('project.icon', ['project_uuid' => $project->uuid, 'v' => $project->updated_at->timestamp]) }}"
                                            alt="{{ $project->name }} icon"
                                            class="h-full w-full rounded-lg object-cover">
                                    @else
                                        <x-reicon name="projects" class="size-4" />
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h3 class="feature-tile-title break-words text-black dark:text-fg">
                                        {{ $project->name }}
                                    </h3>
                                    <p class="feature-tile-copy break-words text-neutral-500 dark:text-fg-faint">
                                        {{ $project->description ?: __('No description') }}
                                    </p>
                                </div>
                            </div>

                            @php
                                $projectRows = $resourceRowsByProject[$project->id] ?? collect();
                            @endphp
                            @if ($projectRows->isNotEmpty())
                                <div class="relative z-10 mt-3 flex flex-wrap gap-1.5" data-project-resource-dots>
                                    @foreach ($projectRows->take(8) as $row)
                                        <a href="{{ $row['url'] }}" {{ wireNavigate() }} title="{{ $row['raw_name'] }}: {{ $stateLabel[$row['state']] }}"
                                            class="inline-flex max-w-full items-center gap-1.5 rounded-md bg-neutral-100 px-2 py-0.5 text-[12px] text-neutral-600 hover:no-underline hover:text-black dark:bg-white/[0.05] dark:text-fg-dim dark:hover:text-fg">
                                            <span class="size-1.5 shrink-0 rounded-full {{ $stateDot[$row['state']] }}"></span>
                                            <span class="truncate">{{ $row['name'] }}</span>
                                        </a>
                                    @endforeach
                                    @if ($projectRows->count() > 8)
                                        <span class="px-1 text-[12px] text-neutral-400 dark:text-fg-faint">+{{ $projectRows->count() - 8 }}</span>
                                    @endif
                                </div>
                            @endif

                            <div class="mt-auto flex items-center justify-between gap-3 pt-4">
                                <p class="feature-tile-copy min-w-0 break-words text-neutral-500 dark:text-fg-dim">
                                    {{ trans_choice(':count environment|:count environments', $project->environments->count(), ['count' => $project->environments->count()]) }},
                                    {{ trans_choice(':count resource|:count resources', $resourceCount, ['count' => $resourceCount]) }}
                                </p>

                                <div class="relative z-10 flex shrink-0 items-center gap-0.5">
                                    @if ($firstEnvironment)
                                        @can('createAnyResource')
                                            <a href="{{ route('project.resource.create', [
                                                'project_uuid' => $project->uuid,
                                                'environment_uuid' => $firstEnvironment->uuid,
                                            ]) }}"
                                                {{ wireNavigate() }}
                                                class="flex size-6.5 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                                title="{{ __('Add resource') }}"
                                                aria-label="Add resource to {{ $project->name }}">
                                                <x-reicon name="plus" class="size-3" />
                                            </a>
                                        @endcan
                                    @endif
                                    @can('update', $project)
                                        <a href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}"
                                            {{ wireNavigate() }}
                                            class="flex size-6.5 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                            title="{{ __('Project settings') }}"
                                            aria-label="Open settings for {{ $project->name }}">
                                            <x-reicon name="settings" class="size-3" />
                                        </a>
                                    @endcan
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="mb-0! min-w-0">
            <div class="mb-3 flex items-end justify-between gap-4">
                <div>
                    <h2 class="text-xl! leading-6! font-semibold! tracking-tight text-black dark:text-fg">
                        {{ __('Servers') }}
                    </h2>
                    <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-faint">
                        {{ __('Infrastructure available for deployments') }}
                    </p>
                </div>
                <a href="{{ route('server.index') }}" {{ wireNavigate() }}
                    class="inline-flex shrink-0 items-center gap-1 text-[12px] font-medium text-neutral-500 transition-colors hover:text-black dark:text-fg-dim dark:hover:text-fg">
                    {{ __('View all') }}
                    <x-reicon name="arrow-right" class="size-3" />
                </a>
            </div>

            @if ($dashboardServers->isEmpty())
                @cannot('create', App\Models\Server::class)
                    <x-empty title="{{ __('No servers assigned') }}"
                        description="{{ __('No servers are assigned yet. Contact an advisor to get access.') }}"
                        icon-name="servers" size="sm" />
                @elseif ($privateKeys->isEmpty())
                    <x-empty title="{{ __('A private key is required') }}"
                        description="{{ __('Add an SSH private key before connecting your first server.') }}"
                        icon-name="keys" size="sm">
                        @can('create', App\Models\PrivateKey::class)
                            <x-slot:contents>
                                <a href="{{ route('security.private-key.index') }}" {{ wireNavigate() }}
                                    class="button button-highlighted">
                                    <x-reicon name="plus" class="size-3.5" />
                                    {{ __('Add private key') }}
                                </a>
                            </x-slot:contents>
                        @endcan
                    </x-empty>
                @else
                    <x-empty title="{{ __('No servers yet') }}"
                        description="{{ __('Connect infrastructure for your deployments.') }}"
                        icon-name="servers" size="sm">
                        @can('create', App\Models\Server::class)
                            <x-slot:contents>
                                <a href="{{ route('server.create') }}" {{ wireNavigate() }}
                                    class="button button-highlighted">
                                    <x-reicon name="plus" class="size-3.5" />
                                    {{ __('New server') }}
                                </a>
                            </x-slot:contents>
                        @endcan
                    </x-empty>
                @endif
            @else
                <div class="grid min-w-0 grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3" data-crystal-shift>
                    @foreach ($dashboardServers as $server)
                        @php
                            $proxyNeedsAttention = $server->proxySet() && ($server->proxy->status !== 'running' || $server->hasCurrentTraefikOutdatedInfo());
                            $sentinelNeedsAttention = $server->isSentinelEnabled() && ! $server->isSentinelLive();

                            [$serverStatus, $serverStatusType] = match (true) {
                                $server->settings->force_disabled => ['Disabled', 'error'],
                                ! $server->settings->is_reachable && ! $server->settings->is_usable => ['Unavailable', 'error'],
                                ! $server->settings->is_reachable => ['Unreachable', 'error'],
                                ! $server->settings->is_usable => ['Not ready', 'warning'],
                                $proxyNeedsAttention || $sentinelNeedsAttention => ['Attention required', 'warning'],
                                default => ['Ready', 'success'],
                            };
                        @endphp

                        <a href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}"
                            {{ wireNavigate() }} aria-label="Open {{ $server->name }}"
                            class="feature-tile group relative flex min-w-0 flex-col rounded-xl border border-neutral-200 bg-white p-4 shadow-sm motion-tile hover:border-neutral-300 hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                            @if ($server->isMetricsEnabled())
                                <livewire:dashboard.server-metrics-chart :server="$server"
                                    :key="'dashboard-server-metrics-'.$server->uuid" />
                            @endif

                            <div class="relative z-10 flex min-w-0 items-start gap-3">
                                <div
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.1] dark:bg-white/[0.04] dark:text-fg-dim">
                                    <x-reicon name="servers" class="size-4" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h3
                                        class="feature-tile-title truncate text-black dark:text-fg">
                                        {{ $server->name }}
                                    </h3>
                                    <p class="feature-tile-copy truncate text-neutral-500 dark:text-fg-faint">
                                        {{ $server->description ?: __('No description') }}
                                    </p>
                                </div>
                                @if ($serverStatusType !== 'success')
                                    <span data-tooltip="{{ $serverStatus }}"
                                        aria-label="Server status: {{ $serverStatus }}"
                                        @class([
                                            'flex size-6 shrink-0 items-center justify-center rounded-md',
                                            'text-orange-500 dark:text-amber-300' => $serverStatusType === 'warning',
                                            'text-red-500 dark:text-red-400' => $serverStatusType === 'error',
                                        ])>
                                        <x-reicon name="alert-triangle" class="size-4" />
                                    </span>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</div>
