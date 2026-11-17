<div class="gc-canvas-page gc-canvas-page--workspace" wire:poll.10s="pollGraph">
    <x-slot:title>
        {{ data_get_str($environment, 'name')->limit(10) }} | {{ __('Canvas') }} | {{ product_name() }}
    </x-slot>

    @viteReactRefresh
    @vite('resources/js/environment-canvas/main.jsx')

    <header class="gc-canvas-toolbar">
        <div class="gc-canvas-toolbar-left min-w-0">
            <a href="{{ route('project.show', ['project_uuid' => $project->uuid]) }}" {{ wireNavigate() }}
                class="gc-canvas-back" title="{{ __('Project') }}">
                <x-reicon name="arrow-left" class="size-3.5" />
            </a>
            <div class="min-w-0">
                <p class="gc-canvas-kicker truncate">{{ $project->name }}</p>
                <h1 class="gc-canvas-title truncate">{{ $environment->name }}</h1>
            </div>
            <span class="gc-canvas-count">
                {{ trans_choice(':count resource|:count resources', $graph['meta']['count'] ?? 0, ['count' => $graph['meta']['count'] ?? 0]) }}
            </span>
        </div>
        <div class="gc-canvas-actions">
            <button type="button" class="button" wire:click="refreshGraph" wire:target="refreshGraph"
                title="{{ __('Refresh status') }}">
                <x-reicon name="refresh" class="size-3.5" wire:loading.class="animate-spin" wire:target="refreshGraph" />
                {{ __('Sync') }}
            </button>
            @can('createAnyResource')
                <a href="{{ route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'catalog' => 1]) }}"
                    {{ wireNavigate() }} class="button button-highlighted">
                    <x-reicon name="plus" class="size-3.5" />
                    {{ __('Create') }}
                </a>
            @endcan
            <a href="{{ route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'list' => 1]) }}"
                {{ wireNavigate() }} class="button" title="{{ __('Advanced list') }}">
                {{ __('List') }}
            </a>
            @if ($canUpdate)
                <button type="button" class="button" wire:click="resetLayout"
                    wire:confirm="{{ __('Reset node positions to the automatic layout?') }}">
                    {{ __('Reset') }}
                </button>
            @endif
        </div>
    </header>

    @if (($graph['meta']['count'] ?? 0) === 0)
        <div class="gc-canvas-empty">
            <x-empty title="{{ __('No resources yet') }}"
                description="{{ __('Launch a service in this environment to manage it on the canvas.') }}"
                icon-name="layers" />
            @can('createAnyResource')
                <a href="{{ route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'catalog' => 1]) }}"
                    {{ wireNavigate() }} class="button button-highlighted mt-4">
                    <x-reicon name="plus" class="size-3.5" />
                    {{ __('Create') }}
                </a>
            @endcan
        </div>
    @else
        <div class="gc-canvas-workspace @if ($inspector) has-inspector @endif">
            <div class="gc-canvas-shell">
                <div
                    id="gc-environment-canvas-root"
                    class="gc-canvas-react"
                    data-gc-environment-canvas
                    wire:ignore
                ></div>
            </div>

            @if ($inspector)
                <aside class="gc-canvas-inspector" wire:key="inspector-{{ $inspector }}-{{ $inspectorNodeId }}">
                    <div class="gc-canvas-inspector-head">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="gc-canvas-inspector-title truncate">{{ $inspectorTitle }}</h2>
                                @if (filled($inspectorStatus))
                                    <span class="gc-canvas-status-pill">{{ $inspectorStatus }}</span>
                                @endif
                            </div>
                            @if (filled($inspectorNodeId))
                                <p class="gc-canvas-kicker mt-0.5 truncate font-mono">{{ $inspectorNodeId }}</p>
                            @endif
                        </div>
                        <button type="button" class="button" wire:click="closeInspector" title="{{ __('Close') }}">
                            <x-reicon name="x" class="size-3.5" />
                        </button>
                    </div>

                    <div class="gc-canvas-inspector-tabs" role="tablist">
                        @if ($inspector === 'github' || $inspectorKind === 'github')
                            <button type="button" role="tab" class="gc-canvas-inspector-tab is-active"
                                wire:click="setInspectorTab('github')">{{ __('GitHub') }}</button>
                        @else
                            <button type="button" role="tab"
                                class="gc-canvas-inspector-tab @if ($inspectorTab === 'details') is-active @endif"
                                wire:click="setInspectorTab('details')">{{ __('Details') }}</button>
                            <button type="button" role="tab"
                                class="gc-canvas-inspector-tab @if ($inspectorTab === 'logs') is-active @endif"
                                wire:click="setInspectorTab('logs')">{{ __('Logs') }}</button>
                            <button type="button" role="tab"
                                class="gc-canvas-inspector-tab @if ($inspectorTab === 'metrics') is-active @endif"
                                wire:click="setInspectorTab('metrics')">{{ __('Metrics') }}</button>
                        @endif
                    </div>

                    <div class="gc-canvas-inspector-body">
                        @if ($inspector === 'github' || $inspectorTab === 'github')
                            @if (filled($githubServiceUuid))
                                <livewire:project.service.configuration
                                    :project_uuid="$project->uuid"
                                    :environment_uuid="$environment->uuid"
                                    :service_uuid="$githubServiceUuid"
                                    :embedded="true"
                                    :key="'canvas-gh-'.$githubServiceUuid"
                                />
                            @else
                                <p class="text-sm text-[var(--dash-dim)]">{{ __('No service linked to this GitHub node.') }}</p>
                            @endif
                        @elseif ($inspectorTab === 'details')
                            <div class="space-y-4 text-sm">
                                <div>
                                    <p class="gc-canvas-kicker">{{ __('Type') }}</p>
                                    <p class="font-medium">{{ $inspectorKind }}</p>
                                </div>
                                @if ($stackRules !== [])
                                    <div>
                                        <p class="gc-canvas-kicker mb-1.5">{{ __('Stack rules') }}</p>
                                        <ul class="gc-canvas-rules">
                                            @foreach ($stackRules as $rule)
                                                <li>{{ $rule }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                                <p class="text-[12px] text-[var(--dash-dim)]">
                                    {{ __('Select Logs or Metrics without leaving this canvas.') }}
                                </p>
                            </div>
                        @elseif ($inspectorTab === 'logs')
                            <div class="mb-2 flex justify-end">
                                <button type="button" class="button" wire:click="refreshLogs">{{ __('Refresh') }}</button>
                            </div>
                            <pre class="gc-canvas-log">{{ $logOutput }}</pre>
                        @elseif ($inspectorTab === 'metrics')
                            <div class="space-y-3 text-sm">
                                @if (filled($sentinelUrl))
                                    <p>{{ __('Metrics come from Sentinel on this server (:status).', ['status' => $sentinelStatus ?? '—']) }}</p>
                                    <a class="button button-highlighted" href="{{ $sentinelUrl }}" {{ wireNavigate() }}>
                                        {{ __('Open Sentinel') }}
                                    </a>
                                @elseif (filled($monitorEmbedUrl))
                                    <iframe title="{{ __('Monitor') }}" src="{{ $monitorEmbedUrl }}"
                                        class="gc-canvas-inspector-frame" referrerpolicy="no-referrer"></iframe>
                                @else
                                    <p class="text-[var(--dash-dim)]">
                                        {{ __('Enable Sentinel on the server to see metrics here. New Odoo stacks no longer launch a separate monitor sidecar when Sentinel is on.') }}
                                    </p>
                                @endif
                            </div>
                        @endif
                    </div>
                </aside>
            @endif
        </div>
        <p class="gc-canvas-hint">
            {{ __('Click a node for Details, Logs, and Metrics in this window. GitHub is its own node.') }}
        </p>
    @endif
</div>

@script
<script>
    const mount = () => {
        const el = document.getElementById('gc-environment-canvas-root');
        if (!el || !window.gcEnvironmentCanvas?.mount) {
            return false;
        }

        window.gcEnvironmentCanvas.mount(el, {
            graph: @js($graph),
            canUpdate: @js($canUpdate),
            bootKey: @js($environment->uuid),
            savePosition: (id, x, y) => $wire.savePosition(id, x, y),
            onInspect: (id, panel) => $wire.openInspector(id, panel),
        });

        return true;
    };

    const boot = () => {
        if (mount()) {
            return;
        }
        let attempts = 0;
        const timer = window.setInterval(() => {
            attempts += 1;
            if (mount() || attempts > 40) {
                window.clearInterval(timer);
            }
        }, 50);
    };

    boot();

    $wire.on('canvas-graph-updated', (payload) => {
        const detail = Array.isArray(payload) ? payload[0] : payload;
        window.dispatchEvent(new CustomEvent('gc-canvas:update', {
            detail: {
                graph: detail?.graph ?? detail,
                replacePositions: !!(detail?.replacePositions),
            },
        }));
    });
</script>
@endscript
