<div class="gc-canvas-page" wire:poll.10s="pollGraph">
    <x-slot:title>
        {{ __('Canvas') }} | {{ data_get_str($environment, 'name')->limit(10) }} | {{ product_name() }}
    </x-slot>

    @viteReactRefresh
    @vite('resources/js/environment-canvas/main.jsx')

    <header class="gc-canvas-head">
        <div class="min-w-0">
            <p class="gc-canvas-kicker">{{ $project->name }} · {{ $environment->name }}</p>
            <h1 class="gc-canvas-title">{{ __('Canvas') }}</h1>
            <p class="gc-canvas-lede">
                {{ trans_choice(':count resource on this environment|:count resources on this environment', $graph['meta']['count'] ?? 0, ['count' => $graph['meta']['count'] ?? 0]) }}
            </p>
        </div>
        <div class="gc-canvas-actions">
            <a href="{{ route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}"
                {{ wireNavigate() }} class="button">
                {{ __('Resources') }}
            </a>
            <a href="{{ route('project.show', ['project_uuid' => $project->uuid]) }}" {{ wireNavigate() }} class="button">
                {{ __('Project') }}
            </a>
            @if ($canUpdate)
                <button type="button" class="button" wire:click="resetLayout"
                    wire:confirm="{{ __('Reset node positions to the automatic layout?') }}">
                    {{ __('Reset layout') }}
                </button>
            @endif
        </div>
    </header>

    @if (($graph['meta']['count'] ?? 0) === 0)
        <x-empty title="{{ __('No resources yet') }}"
            description="{{ __('Launch a service in this environment to see it on the canvas.') }}"
            icon-name="layers" />
    @else
        <div class="gc-canvas-shell">
            <div
                id="gc-environment-canvas-root"
                class="gc-canvas-react"
                data-gc-environment-canvas
                wire:ignore
            ></div>
            <p class="gc-canvas-hint">
                @if ($canUpdate)
                    {{ __('Drag nodes to arrange. Scroll to zoom, drag the canvas to pan. Double-click or Open to visit a resource. Status refreshes live.') }}
                @else
                    {{ __('View only. Scroll to zoom and drag the canvas to pan. Ask an admin to rearrange the canvas.') }}
                @endif
            </p>
        </div>
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
