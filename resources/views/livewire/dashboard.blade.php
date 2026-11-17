<div class="application-settings-form gc-dash w-full">
    <x-slot:title>
        {{ __('Dashboard') }} | {{ product_name() }}
    </x-slot>

    @if (session('error'))
        <span x-data x-init="$wire.dispatch('error', @js(session('error')))" />
    @endif

    @php
        $totals = $overview['totals'];
        $projectRows = $overview['projects']->take(6);
        $hiddenProjects = max(0, $overview['projects']->count() - $projectRows->count());
        $attention = $overview['attention'];
        $attentionTotal = $overview['attentionTotal'];
        $attentionTone = match (true) {
            $attention->contains('severity', 'error') => 'error',
            $attentionTotal > 0 => 'warning',
            $totals['resources'] > 0 && $totals['online'] === 0 && $totals['starting'] === 0 => 'idle',
            default => 'ok',
        };
        $onlinePercent = $totals['resources'] > 0 ? (int) round($totals['online'] / $totals['resources'] * 100) : null;
        $firstName = str(auth()->user()?->name ?? '')->before(' ')->toString();
        $canCreateServer = auth()->user()?->can('create', App\Models\Server::class) ?? false;
        $needsSetup = $totals['projects'] === 0 || $totals['resources'] === 0;

        $statusLine = match (true) {
            $totals['resources'] === 0 && $totals['projects'] === 0 => __('Let’s get your first project online.'),
            $totals['resources'] === 0 => __('Your projects are ready for their first service.'),
            $attentionTotal > 0 => trans_choice('{1} :count item needs your attention.|[2,*] :count items need your attention.', $attentionTotal, ['count' => $attentionTotal]),
            $totals['starting'] > 0 => __('Everything is on track. Some services are still starting.'),
            $totals['online'] === 0 => trans_choice('{1} Your service is stopped.|[2,*] All :count services are stopped.', $totals['offline'], ['count' => $totals['offline']]),
            $totals['offline'] > 0 => trans_choice('{1} Everything running is healthy. :count service is stopped.|[2,*] Everything running is healthy. :count services are stopped.', $totals['offline'], ['count' => $totals['offline']]),
            default => __('Everything is online.'),
        };

        $healthLabel = fn (array $row) => match ($row['health']) {
            'empty' => __('No services yet'),
            'online' => __(':online of :total online', ['online' => $row['online'], 'total' => $row['resources']]),
            'starting' => __('Starting'),
            'degraded' => __('Unhealthy'),
            'offline' => __('Stopped'),
            default => __(':online of :total online', ['online' => $row['online'], 'total' => $row['resources']]),
        };

    @endphp

    <div class="gc-dash-stack">
        <header class="gc-dash-hero" data-tone="{{ $attentionTone }}">
            <div class="min-w-0">
                <p class="gc-dash-kicker">
                    <time x-data x-text="(d => d.charAt(0).toUpperCase() + d.slice(1))(new Date().toLocaleDateString(@js(str_replace('_', '-', app()->getLocale())), { weekday: 'long', day: 'numeric', month: 'long' }))"></time>
                </p>
                <h1 class="gc-dash-title" x-data="{ hour: new Date().getHours() }"
                    x-text="(hour < 12 ? @js(__('Good morning')) : hour < 19 ? @js(__('Good afternoon')) : @js(__('Good evening'))) + @js($firstName !== '' ? ', '.$firstName : '')">
                    {{ __('Welcome back') }}{{ $firstName !== '' ? ', '.$firstName : '' }}
                </h1>
                <p class="gc-dash-status">
                    <span class="gc-dash-pulse" aria-hidden="true"></span>
                    @if ($attentionTotal > 0)
                        <a href="#gc-dash-attention" class="hover:underline">{{ $statusLine }}</a>
                    @else
                        <span>{{ $statusLine }}</span>
                    @endif
                </p>
            </div>

            <div class="gc-dash-actions">
                <button type="button" @click="$dispatch('open-global-search')" class="gc-dash-search">
                    <x-reicon name="search" class="size-3.5" />
                    <span>{{ __('Search or jump to…') }}</span>
                    <kbd x-data
                        x-text="(/Mac|iPhone|iPad|iPod/i.test(navigator.userAgentData?.platform || navigator.platform || '') ? '⌘' : 'Ctrl+') + 'K'"></kbd>
                </button>
                @can('createAnyResource')
                    <x-modal-input title="{{ __('New Project') }}">
                        <x-slot:content>
                            <button type="button" class="button button-highlighted gc-dash-primary">
                                <x-reicon name="plus" class="size-3.5" />
                                {{ __('New project') }}
                            </button>
                        </x-slot:content>
                        <livewire:project.add-empty key="dashboard-new-project" />
                    </x-modal-input>
                @endcan
            </div>
        </header>

        <section class="gc-dash-stats" aria-label="{{ __('Overview') }}">
            <a href="{{ route('project.index') }}" {{ wireNavigate() }} class="gc-dash-stat">
                <span class="gc-dash-stat-label">
                    <x-reicon name="projects" class="size-3.5" />
                    {{ __('Projects') }}
                </span>
                <span class="gc-dash-stat-value">{{ $totals['projects'] }}</span>
                <span class="gc-dash-stat-hint">
                    {{ trans_choice('{0} No environments|{1} :count environment|[2,*] :count environments', $totals['environments'], ['count' => $totals['environments']]) }}
                </span>
            </a>

            <div class="gc-dash-stat" data-tone="{{ $totals['degraded'] > 0 ? 'error' : ($totals['online'] > 0 ? 'ok' : 'idle') }}">
                <span class="gc-dash-stat-label">
                    <x-reicon name="layers" class="size-3.5" />
                    {{ __('Services online') }}
                </span>
                <span class="gc-dash-stat-value">
                    {{ $totals['online'] }}<span class="gc-dash-stat-of">/{{ $totals['resources'] }}</span>
                </span>
                <span class="gc-dash-stat-hint">
                    @if ($totals['degraded'] > 0)
                        {{ trans_choice('{1} :count with problems|[2,*] :count with problems', $totals['degraded'], ['count' => $totals['degraded']]) }}
                    @elseif ($totals['starting'] > 0)
                        {{ trans_choice('{1} :count starting|[2,*] :count starting', $totals['starting'], ['count' => $totals['starting']]) }}
                    @elseif ($totals['offline'] > 0)
                        {{ trans_choice('{1} :count stopped|[2,*] :count stopped', $totals['offline'], ['count' => $totals['offline']]) }}
                    @else
                        {{ $totals['resources'] > 0 ? __('All running') : __('No services yet') }}
                    @endif
                </span>
            </div>

            <a href="#gc-dash-attention" class="gc-dash-stat" data-tone="{{ $attentionTone }}">
                <span class="gc-dash-stat-label">
                    <x-reicon name="{{ $attentionTotal > 0 ? 'alert-triangle' : 'check-circle' }}" class="size-3.5" />
                    {{ __('Needs attention') }}
                </span>
                <span class="gc-dash-stat-value">{{ $attentionTotal }}</span>
                <span class="gc-dash-stat-hint">
                    {{ $attentionTotal > 0 ? __('Review and fix') : __('Nothing pending') }}
                </span>
            </a>

            <a href="{{ route('server.index') }}" {{ wireNavigate() }} class="gc-dash-stat"
                data-tone="{{ $totals['servers'] > 0 && $totals['serversReady'] < $totals['servers'] ? 'warning' : 'ok' }}">
                <span class="gc-dash-stat-label">
                    <x-reicon name="servers" class="size-3.5" />
                    {{ __('Servers') }}
                </span>
                <span class="gc-dash-stat-value">
                    {{ $totals['serversReady'] }}<span class="gc-dash-stat-of">/{{ $totals['servers'] }}</span>
                </span>
                <span class="gc-dash-stat-hint">{{ __('Ready for deployments') }}</span>
            </a>
        </section>

        <div class="gc-dash-layout">
            <section class="gc-dash-panel min-w-0" aria-labelledby="gc-dash-projects-title">
                <div class="gc-dash-panel-head">
                    <div class="min-w-0">
                        <h2 id="gc-dash-projects-title" class="gc-dash-panel-title">{{ __('Projects') }}</h2>
                        <p class="gc-dash-panel-copy">{{ __('Problems first, then the most recently changed') }}</p>
                    </div>
                    <a href="{{ route('project.index') }}" {{ wireNavigate() }} class="gc-dash-link">
                        {{ __('View all') }}
                        <x-reicon name="arrow-right" class="size-3" />
                    </a>
                </div>

                @if ($needsSetup)
                    @php
                        $setupSteps = collect([
                            $canCreateServer ? [
                                'done' => $privateKeys->isNotEmpty(),
                                'title' => __('Add an SSH key'),
                                'copy' => __('Coolify uses it to reach your servers.'),
                                'href' => route('security.private-key.index'),
                            ] : null,
                            $canCreateServer ? [
                                'done' => $totals['servers'] > 0,
                                'title' => __('Connect a server'),
                                'copy' => __('Where your services will run.'),
                                'href' => route('server.create'),
                            ] : null,
                            [
                                'done' => $totals['projects'] > 0,
                                'title' => __('Create a project'),
                                'copy' => __('Groups production and staging environments.'),
                                'href' => route('project.index'),
                            ],
                            [
                                'done' => $totals['resources'] > 0,
                                'title' => __('Launch your first service'),
                                'copy' => __('Pick Odoo or any template from the catalog.'),
                                'href' => $projectRows->first() ? $projectRows->first()['model']->navigateTo() : route('project.index'),
                            ],
                        ])->filter()->values();
                        $nextStep = $setupSteps->search(fn ($step) => ! $step['done']);
                    @endphp

                    <ol class="gc-dash-setup">
                        @foreach ($setupSteps as $index => $step)
                            <li @class(['gc-dash-setup-step', 'is-done' => $step['done'], 'is-next' => $index === $nextStep])>
                                <span class="gc-dash-setup-mark" aria-hidden="true">
                                    @if ($step['done'])
                                        <x-reicon name="check" class="size-3" />
                                    @else
                                        {{ $index + 1 }}
                                    @endif
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="gc-dash-setup-title">{{ $step['title'] }}</span>
                                    <span class="gc-dash-setup-copy">{{ $step['copy'] }}</span>
                                </span>
                                @if (! $step['done'] && $index === $nextStep)
                                    <a href="{{ $step['href'] }}" {{ wireNavigate() }} class="button button-highlighted">
                                        {{ __('Start') }}
                                    </a>
                                @endif
                            </li>
                        @endforeach
                    </ol>
                @endif

                @if ($projectRows->isNotEmpty())
                    <ul class="gc-dash-list">
                        @foreach ($projectRows as $row)
                            @php
                                $project = $row['model'];
                                $firstEnvironment = $project->environments->first();
                            @endphp
                            <li wire:key="dashboard-project-{{ $project->uuid }}" class="gc-dash-row"
                                data-health="{{ $row['health'] }}">
                                <a href="{{ $project->navigateTo() }}" {{ wireNavigate() }} class="gc-dash-row-link"
                                    aria-label="{{ __('Open :name', ['name' => $project->name]) }}"></a>

                                <span class="gc-dash-row-icon">
                                    @if ($project->icon_path)
                                        <img src="{{ route('project.icon', ['project_uuid' => $project->uuid, 'v' => $project->updated_at->timestamp]) }}"
                                            alt="" class="size-full rounded-[inherit] object-cover">
                                    @else
                                        <x-reicon name="projects" class="size-4" />
                                    @endif
                                    <span class="gc-dash-dot" aria-hidden="true"></span>
                                </span>

                                <span class="gc-dash-row-main">
                                    <span class="gc-dash-row-title">{{ $project->name }}</span>
                                    <span class="gc-dash-row-copy">{{ $project->description ?: __('No description') }}</span>
                                </span>

                                <span class="gc-dash-row-envs">
                                    @if ($row['production'] > 0)
                                        <span class="gc-dash-chip" data-role="production">
                                            {{ __('Production') }}@if ($row['production'] > 1) <b>{{ $row['production'] }}</b>@endif
                                        </span>
                                    @endif
                                    @if ($row['staging'] > 0)
                                        <span class="gc-dash-chip" data-role="staging">
                                            {{ __('Staging') }}@if ($row['staging'] > 1) <b>{{ $row['staging'] }}</b>@endif
                                        </span>
                                    @endif
                                </span>

                                <span class="gc-dash-row-health">
                                    <span class="gc-dash-dot" aria-hidden="true"></span>
                                    <span class="gc-dash-row-health-label">{{ $healthLabel($row) }}</span>
                                </span>

                                <span class="gc-dash-row-actions">
                                    @if ($firstEnvironment)
                                        @can('createAnyResource')
                                            <a href="{{ route('project.resource.create', ['project_uuid' => $project->uuid, 'environment_uuid' => $firstEnvironment->uuid]) }}"
                                                {{ wireNavigate() }} class="gc-dash-icon-button"
                                                data-tooltip="{{ __('Add resource') }}"
                                                aria-label="{{ __('Add resource to :name', ['name' => $project->name]) }}">
                                                <x-reicon name="plus" class="size-3.5" />
                                            </a>
                                        @endcan
                                    @endif
                                    @can('update', $project)
                                        <a href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}"
                                            {{ wireNavigate() }} class="gc-dash-icon-button"
                                            data-tooltip="{{ __('Project settings') }}"
                                            aria-label="{{ __('Open settings for :name', ['name' => $project->name]) }}">
                                            <x-reicon name="settings" class="size-3.5" />
                                        </a>
                                    @endcan
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($hiddenProjects > 0)
                        <a href="{{ route('project.index') }}" {{ wireNavigate() }} class="gc-dash-more">
                            {{ trans_choice('{1} :count more project|[2,*] :count more projects', $hiddenProjects, ['count' => $hiddenProjects]) }}
                            <x-reicon name="arrow-right" class="size-3" />
                        </a>
                    @endif
                @elseif (! $needsSetup)
                    <x-empty title="{{ __('No projects yet') }}"
                        description="{{ __('Use New to create your first deployment workspace.') }}"
                        icon-name="projects" size="sm" />
                @endif
            </section>

            <aside class="gc-dash-aside">
                <section class="gc-dash-panel" aria-labelledby="gc-dash-deploys-title">
                    <div class="gc-dash-panel-head">
                        <div class="min-w-0">
                            <h2 id="gc-dash-deploys-title" class="gc-dash-panel-title">{{ __('Deploys') }}</h2>
                            <p class="gc-dash-panel-copy">{{ __('Latest activity across your projects') }}</p>
                        </div>
                        <a href="{{ route('deployments.index') }}" {{ wireNavigate() }} class="gc-dash-link">
                            {{ __('View all') }}
                            <x-reicon name="arrow-right" class="size-3" />
                        </a>
                    </div>
                    <livewire:deployments.index compact :limit="5" key="dashboard-deploys" />
                </section>

                <section id="gc-dash-attention" class="gc-dash-panel" data-tone="{{ $attentionTone }}"
                    aria-labelledby="gc-dash-attention-title">
                    <div class="gc-dash-panel-head">
                        <div class="min-w-0">
                            <h2 id="gc-dash-attention-title" class="gc-dash-panel-title">{{ __('Needs attention') }}</h2>
                            <p class="gc-dash-panel-copy">{{ __('Unhealthy or crashed services and servers with problems') }}</p>
                        </div>
                    </div>

                    @if ($attention->isEmpty())
                        <div class="gc-dash-calm">
                            <span class="gc-dash-calm-mark"><x-reicon name="check" class="size-4" /></span>
                            <span>
                                <span class="gc-dash-setup-title">{{ __('All clear') }}</span>
                                <span class="gc-dash-setup-copy">{{ __('Nothing needs your attention right now.') }}</span>
                            </span>
                        </div>
                    @else
                        <ul class="gc-dash-alerts">
                            @foreach ($attention as $item)
                                <li wire:key="dashboard-attention-{{ md5($item['kind'].$item['name'].$item['context']) }}">
                                    <a @if ($item['link']) href="{{ $item['link'] }}" {{ wireNavigate() }} @endif
                                        class="gc-dash-alert" data-severity="{{ $item['severity'] }}">
                                        <span class="gc-dash-dot" aria-hidden="true"></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="gc-dash-alert-title">{{ $item['name'] }}</span>
                                            <span class="gc-dash-alert-context">{{ $item['context'] }}</span>
                                        </span>
                                        <span class="gc-dash-alert-status">{{ $item['status'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                        @if ($attentionTotal > $attention->count())
                            <p class="gc-dash-panel-copy px-1 pt-2">
                                {{ trans_choice('{1} And :count more.|[2,*] And :count more.', $attentionTotal - $attention->count(), ['count' => $attentionTotal - $attention->count()]) }}
                            </p>
                        @endif
                    @endif
                </section>

                <section class="gc-dash-panel" aria-labelledby="gc-dash-servers-title">
                    <div class="gc-dash-panel-head">
                        <div class="min-w-0">
                            <h2 id="gc-dash-servers-title" class="gc-dash-panel-title">{{ __('Servers') }}</h2>
                            <p class="gc-dash-panel-copy">{{ __('Infrastructure available for deployments') }}</p>
                        </div>
                        <a href="{{ route('server.index') }}" {{ wireNavigate() }} class="gc-dash-link">
                            {{ __('View all') }}
                            <x-reicon name="arrow-right" class="size-3" />
                        </a>
                    </div>

                    @if ($overview['servers']->isEmpty())
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
                        <ul class="gc-dash-servers">
                            @foreach ($overview['servers']->take(4) as $row)
                                @php($server = $row['model'])
                                <li wire:key="dashboard-server-{{ $server->uuid }}">
                                    <a href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}"
                                        {{ wireNavigate() }} class="gc-dash-server"
                                        data-severity="{{ $row['type'] }}"
                                        aria-label="{{ __('Open :name', ['name' => $server->name]) }}">
                                        @if ($server->isMetricsEnabled())
                                            <livewire:dashboard.server-metrics-chart :server="$server"
                                                :key="'dashboard-server-metrics-'.$server->uuid" />
                                        @endif
                                        <span class="gc-dash-row-icon">
                                            <x-reicon name="servers" class="size-4" />
                                            <span class="gc-dash-dot" aria-hidden="true"></span>
                                        </span>
                                        <span class="relative min-w-0 flex-1">
                                            <span class="gc-dash-alert-title">{{ $server->name }}</span>
                                            <span class="gc-dash-alert-context">{{ $server->description ?: $server->ip }}</span>
                                        </span>
                                        <span class="gc-dash-alert-status relative">{{ __($row['status']) }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </aside>
        </div>
    </div>
</div>
