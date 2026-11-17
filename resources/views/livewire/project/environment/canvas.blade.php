<div class="gc-canvas-page gc-canvas-page--workspace" wire:poll.25s="pollGraph"
    x-on:livewire:navigating.window="Livewire.dispatch('canvas-agent-side-chat', { active: false })">
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
        <div class="gc-canvas-actions" role="toolbar" aria-label="{{ __('Canvas actions') }}">
            @can('createAnyResource')
                <x-modal-input title="{{ __('Add to canvas') }}"
                    subtitle="{{ __('Category → service → connect on the canvas → Deploy.') }}"
                    wireOpen="createAssistantOpen" :wireIgnore="false" :isLarge="true" :fixedHeight="true"
                    :inline="true" data-listbox-portal-root>
                    <x-slot:content>
                        <button type="button" class="button gc-canvas-action--primary" wire:click="openCreateAssistant"
                            title="{{ __('Add to canvas') }}">
                            <x-reicon name="plus" class="size-3.5" />
                            <span class="gc-canvas-action-label">{{ __('Add') }}</span>
                        </button>
                    </x-slot:content>
                    @include('livewire.project.environment.partials.create-assistant', [
                        'createStep' => $createStep,
                        'createCategory' => $createCategory,
                        'createCategories' => $createCategories,
                        'createGroups' => $createGroups,
                        'selectedService' => $selectedService,
                        'versionOptions' => $versionOptions,
                        'needsVersionChoice' => $needsVersionChoice,
                        'lockedOdooVersion' => $lockedOdooVersion,
                        'serverChoices' => $serverChoices,
                        'hasOtherServers' => $hasOtherServers,
                        'needsServer' => $needsServer,
                        'canAddServer' => $canAddServer,
                        'projectServerLocked' => $projectServerLocked,
                        'projectServerName' => $projectServerName,
                    ])
                </x-modal-input>
            @endcan
            @if ($canDeployPending && ($pendingDeployCount ?? 0) > 0)
                <button type="button" class="button button-highlighted gc-canvas-action--primary"
                    wire:click="deployPendingServices" wire:target="deployPendingServices"
                    title="{{ __('Start containers for services that are not running yet') }}">
                    <x-reicon name="play-circle" class="size-3.5" />
                    <span class="gc-canvas-action-label">{{ __('Deploy') }}</span>
                    <span class="gc-canvas-action-count">{{ $pendingDeployCount }}</span>
                </button>
            @endif
            @if (filled($primaryConfigurationUrl))
                <a href="{{ $primaryConfigurationUrl }}" {{ wireNavigate() }}
                    class="button gc-canvas-action--icon" title="{{ __('General configuration') }}"
                    aria-label="{{ __('Configuration') }}">
                    <x-reicon name="settings" class="size-3.5" />
                    <span class="gc-canvas-action-label">{{ __('Configuration') }}</span>
                </a>
            @endif
            <button type="button" class="button gc-canvas-action--icon" wire:click="refreshGraph"
                wire:target="refreshGraph" title="{{ __('Refresh status') }}"
                aria-label="{{ __('Sync') }}">
                <x-reicon name="refresh" class="size-3.5" wire:loading.class="animate-spin" wire:target="refreshGraph" />
                <span class="gc-canvas-action-label">{{ __('Sync') }}</span>
            </button>
            @if ($canUpdate && $canSaveStackTemplate)
                <x-modal-input title="{{ __('Save as stack template') }}"
                    subtitle="{{ __('Reuse this environment’s services and intranet links from My stacks.') }}"
                    wireOpen="saveStackOpen" :wireIgnore="false" :inline="true">
                    <x-slot:content>
                        <button type="button" class="button gc-canvas-action--icon" wire:click="openSaveStackTemplate"
                            title="{{ __('Save as stack template') }}"
                            aria-label="{{ __('Save template') }}">
                            <x-reicon name="documentation" class="size-3.5" />
                            <span class="gc-canvas-action-label">{{ __('Save template') }}</span>
                        </button>
                    </x-slot:content>
                    <div class="flex flex-col gap-3">
                        <x-forms.input id="stackTemplateName" label="{{ __('Name') }}"
                            wire:model="stackTemplateName" required
                            canGate="update" :canResource="$project" />
                        <x-forms.textarea id="stackTemplateDescription" label="{{ __('Description') }}"
                            wire:model="stackTemplateDescription" rows="3"
                            canGate="update" :canResource="$project" />
                        <div class="flex justify-end gap-2">
                            <button type="button" class="button" wire:click="closeSaveStackTemplate">
                                {{ __('Cancel') }}
                            </button>
                            <button type="button" class="button button-highlighted" wire:click="saveStackTemplate"
                                wire:target="saveStackTemplate">
                                {{ __('Save template') }}
                            </button>
                        </div>
                    </div>
                </x-modal-input>
            @endif
            <a href="{{ route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'list' => 1]) }}"
                {{ wireNavigate() }} class="button gc-canvas-action--icon" title="{{ __('Advanced list') }}"
                aria-label="{{ __('List') }}">
                <x-reicon name="layers" class="size-3.5" />
                <span class="gc-canvas-action-label">{{ __('List') }}</span>
            </a>
            @if ($canUpdate)
                <button type="button" class="button gc-canvas-action--icon" wire:click="resetLayout"
                    wire:confirm="{{ __('Reset node positions to the automatic layout?') }}"
                    title="{{ __('Reset layout') }}" aria-label="{{ __('Reset') }}">
                    <x-reicon name="time-back" class="size-3.5" />
                    <span class="gc-canvas-action-label">{{ __('Reset') }}</span>
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
                <button type="button" class="button button-highlighted mt-4" wire:click="openCreateAssistant">
                    <x-reicon name="plus" class="size-3.5" />
                    {{ __('Add') }}
                </button>
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
                            <div class="flex min-w-0 flex-wrap items-center gap-2">
                                <h2 class="gc-canvas-inspector-title truncate">{{ $inspectorTitle }}</h2>
                                @if (filled($inspectorStatus))
                                    <span class="gc-canvas-status-pill is-{{ $inspectorStatusTone }}">{{ $inspectorStatus }}</span>
                                @endif
                            </div>
                            @if (filled($inspectorKind))
                                <p class="gc-canvas-kicker mt-0.5 truncate">{{ str($inspectorKind)->headline() }}</p>
                            @endif
                        </div>
                        <button type="button" class="gc-canvas-inspector-close" wire:click="closeInspector"
                            title="{{ __('Close') }}" aria-label="{{ __('Close') }}">
                            <x-reicon name="x" class="size-3.5" />
                        </button>
                    </div>

                    <div class="gc-canvas-inspector-tabs" role="tablist">
                        @if ($inspector === 'github' || $inspectorKind === 'github')
                            <button type="button" role="tab" class="gc-canvas-inspector-tab is-active"
                                wire:click="setInspectorTab('github')">{{ __('GitHub') }}</button>
                        @elseif ($inspector === 'agent' || $inspectorKind === 'agent')
                            <button type="button" role="tab"
                                class="gc-canvas-inspector-tab @if ($inspectorTab === 'chat') is-active @endif"
                                wire:click="setInspectorTab('chat')">{{ __('Chat') }}</button>
                            <button type="button" role="tab"
                                class="gc-canvas-inspector-tab @if ($inspectorTab === 'settings') is-active @endif"
                                wire:click="setInspectorTab('settings')">{{ __('Settings') }}</button>
                        @elseif ($inspectorKind === 'volume' || $inspectorKind === 'domain')
                            <button type="button" role="tab"
                                class="gc-canvas-inspector-tab @if ($inspectorTab === 'settings') is-active @endif"
                                wire:click="setInspectorTab('settings')">{{ __('Settings') }}</button>
                            <button type="button" role="tab"
                                class="gc-canvas-inspector-tab @if ($inspectorTab === 'details') is-active @endif"
                                wire:click="setInspectorTab('details')">{{ __('Details') }}</button>
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
                        @if ($inspector === 'agent' || $inspectorKind === 'agent')
                            @if ($inspectorTab === 'settings')
                                <livewire:project.agent.settings :project_uuid="$project->uuid"
                                    :key="'canvas-agent-settings-'.$project->uuid" />
                            @else
                                <livewire:project.agent.chat :project_uuid="$project->uuid"
                                    :environment_uuid="$environment->uuid"
                                    :key="'canvas-agent-chat-'.$project->uuid.'-'.$environment->uuid" />
                            @endif
                        @elseif ($inspector === 'github' || $inspectorTab === 'github')
                            @if (filled($githubServiceUuid))
                                <div class="gc-canvas-github-embed space-y-3">
                                    @if (filled($configurationUrl))
                                        <a href="{{ $configurationUrl }}" {{ wireNavigate() }}
                                            class="button inline-flex w-full justify-center">
                                            <x-reicon name="settings" class="size-3.5" />
                                            {{ __('Open full configuration') }}
                                        </a>
                                    @endif
                                    <livewire:project.service.configuration
                                        :embed-project-uuid="$project->uuid"
                                        :embed-environment-uuid="$environment->uuid"
                                        :embed-service-uuid="$githubServiceUuid"
                                        :embedded="true"
                                        :key="'canvas-gh-'.$githubServiceUuid"
                                    />
                                </div>
                            @else
                                <p class="text-sm text-[var(--dash-dim)]">{{ __('No service linked to this GitHub node.') }}</p>
                            @endif
                        @elseif ($inspectorTab === 'settings' && $inspectorKind === 'domain')
                            <div class="space-y-4 text-sm">
                                <div>
                                    <p class="gc-canvas-kicker">{{ __('Domain') }}</p>
                                    <p class="font-medium font-mono text-[13px]">{{ $inspectorTitle }}</p>
                                </div>
                                @if (filled($domainFqdn))
                                    <div>
                                        <p class="gc-canvas-kicker">{{ __('Public URL') }}</p>
                                        <a href="{{ $domainFqdn }}" target="_blank" rel="noopener noreferrer"
                                            class="break-all font-mono text-[13px] underline underline-offset-2">{{ $domainFqdn }}</a>
                                    </div>
                                @endif
                                <p class="text-[12px] text-[var(--dash-dim)]">
                                    {{ __('Edit hosts, HTTPS, redirects, and the environment address here. Saving updates the public route for this service.') }}
                                </p>
                                @if (filled($volumeParentId))
                                    <div>
                                        <p class="gc-canvas-kicker">{{ __('Routes to') }}</p>
                                        <button type="button" class="button mt-1"
                                            wire:click="openInspector(@js($volumeParentId), 'details')">
                                            {{ $volumeParentId }}
                                        </button>
                                    </div>
                                @endif
                                @if (filled($domainsUrl))
                                    <a href="{{ $domainsUrl }}" {{ wireNavigate() }}
                                        class="button button-highlighted inline-flex w-full justify-center">
                                        <x-reicon name="globe" class="size-3.5" />
                                        {{ __('Configure domains') }}
                                    </a>
                                @endif
                            </div>
                        @elseif ($inspectorTab === 'settings' && $inspectorKind === 'volume')
                            <div class="space-y-4 text-sm">
                                <div>
                                    <p class="gc-canvas-kicker">{{ __('Volume') }}</p>
                                    <p class="font-medium font-mono text-[13px]">{{ $inspectorTitle }}</p>
                                </div>
                                @if (filled($volumeMountPath))
                                    <div>
                                        <p class="gc-canvas-kicker">{{ __('Mount path') }}</p>
                                        <p class="font-mono text-[13px]">{{ $volumeMountPath }}</p>
                                    </div>
                                @endif
                                @if (filled($volumeParentId))
                                    <div>
                                        <p class="gc-canvas-kicker">{{ __('Attached to') }}</p>
                                        <button type="button" class="button mt-1"
                                            wire:click="openInspector(@js($volumeParentId), 'details')">
                                            {{ $volumeParentId }}
                                        </button>
                                    </div>
                                @endif
                                <div class="gc-canvas-volume-danger space-y-3">
                                    <p class="text-[13px] font-medium text-rose-300">{{ __('Danger zone') }}</p>
                                    @if ($volumeIsOrphan)
                                        <p class="text-[12px] text-[var(--dash-dim)]">
                                            {{ __('This volume was kept after its service was deleted. Deleting it here removes the Docker volume permanently.') }}
                                        </p>
                                        <x-forms.input id="volumeDeleteConfirm"
                                            label="{{ __('Type :name to confirm', ['name' => $inspectorTitle]) }}" />
                                        <button type="button" class="button w-full"
                                            style="color:#fecaca;border-color:color-mix(in oklab,#f43f5e 45%,transparent)"
                                            wire:click="deleteOrphanVolume"
                                            wire:confirm="{{ __('Permanently delete this orphan volume?') }}">
                                            {{ __('Delete orphan volume') }}
                                        </button>
                                    @else
                                        <p class="text-[12px] text-[var(--dash-dim)]">
                                            {{ __('Attached volumes are managed from storage configuration. If you delete the service without deleting volumes, they stay here as orphans.') }}
                                        </p>
                                        @if (filled($configurationUrl))
                                            <a href="{{ $configurationUrl }}" {{ wireNavigate() }}
                                                class="button button-highlighted inline-flex w-full justify-center">
                                                <x-reicon name="settings" class="size-3.5" />
                                                {{ __('Open storage configuration') }}
                                            </a>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        @elseif ($inspectorTab === 'details')
                            <div class="gc-canvas-details">
                                @if (filled($openHref) || filled($configurationUrl))
                                    <div class="gc-canvas-details-actions">
                                        @if (filled($openHref))
                                            <a href="{{ $openHref }}" target="_blank" rel="noopener noreferrer"
                                                class="button button-highlighted">
                                                <x-reicon name="external-link" class="size-3.5" />
                                                {{ __('Open service') }}
                                            </a>
                                        @endif
                                        @if (filled($configurationUrl))
                                            <a href="{{ $configurationUrl }}" {{ wireNavigate() }} class="button">
                                                <x-reicon name="settings" class="size-3.5" />
                                                {{ __('Configuration') }}
                                            </a>
                                        @endif
                                    </div>
                                @endif

                                @if ($inspectorDomains !== [])
                                    <section class="gc-canvas-details-section">
                                        <div class="gc-canvas-details-section-head">
                                            <h3>{{ __('Domains') }}</h3>
                                            @php
                                                $domainConfig = collect($inspectorDomains)->first(
                                                    fn (array $row): bool => filled($row['config_href'] ?? null)
                                                );
                                            @endphp
                                            @if (filled($domainConfig['config_href'] ?? null))
                                                <a href="{{ $domainConfig['config_href'] }}" {{ wireNavigate() }}
                                                    class="gc-canvas-details-link">{{ __('Configure domains') }}</a>
                                            @endif
                                        </div>
                                        <ul class="gc-canvas-details-list">
                                            @foreach ($inspectorDomains as $domain)
                                                <li>
                                                    @if (filled($domain['fqdn'] ?? null))
                                                        <a href="{{ $domain['fqdn'] }}" target="_blank" rel="noopener noreferrer"
                                                            class="gc-canvas-details-mono">{{ $domain['fqdn'] }}</a>
                                                    @else
                                                        <span class="gc-canvas-details-mono">{{ $domain['label'] }}</span>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </section>
                                @endif

                                @if ($inspectorVolumes !== [])
                                    <section class="gc-canvas-details-section">
                                        <div class="gc-canvas-details-section-head">
                                            <h3>{{ __('Volumes') }}</h3>
                                            @php
                                                $volumeConfig = collect($inspectorVolumes)->first(
                                                    fn (array $row): bool => filled($row['config_href'] ?? null)
                                                );
                                            @endphp
                                            @if (filled($volumeConfig['config_href'] ?? null))
                                                <a href="{{ $volumeConfig['config_href'] }}" {{ wireNavigate() }}
                                                    class="gc-canvas-details-link">{{ __('Open storage configuration') }}</a>
                                            @endif
                                        </div>
                                        <ul class="gc-canvas-details-list">
                                            @foreach ($inspectorVolumes as $volume)
                                                <li>
                                                    <span class="gc-canvas-details-name">{{ $volume['label'] }}</span>
                                                    @if (filled($volume['mount_path'] ?? null))
                                                        <span class="gc-canvas-details-meta">{{ $volume['mount_path'] }}</span>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </section>
                                @endif

                                @if ($connectedNodes !== [])
                                    <section class="gc-canvas-details-section">
                                        <div class="gc-canvas-details-section-head">
                                            <h3>{{ __('Connected on canvas') }}</h3>
                                        </div>
                                        <ul class="gc-canvas-details-connections">
                                            @foreach ($connectedNodes as $connected)
                                                <li wire:key="conn-{{ $inspectorNodeId }}-{{ $connected['id'] }}">
                                                    <button type="button" class="gc-canvas-details-conn-open"
                                                        wire:click="openInspector(@js($connected['id']), 'details')">
                                                        <span class="gc-canvas-details-name">{{ $connected['label'] }}</span>
                                                        <span class="gc-canvas-details-meta">{{ str($connected['kind'])->headline() }}</span>
                                                    </button>
                                                    @if ($canUpdate && ($connected['removable'] ?? false))
                                                        <button type="button" class="gc-canvas-details-conn-remove"
                                                            wire:click="disconnectFromInspector(@js($connected['id']), @js($connected['edge_id'] ?? null))"
                                                            title="{{ __('Remove connection') }}"
                                                            aria-label="{{ __('Remove connection') }}">
                                                            <x-reicon name="x" class="size-3.5" />
                                                        </button>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </section>
                                @endif

                                @if ($stackRules !== [])
                                    <section class="gc-canvas-details-section">
                                        <div class="gc-canvas-details-section-head">
                                            <h3>{{ __('Stack rules') }}</h3>
                                        </div>
                                        <ul class="gc-canvas-rules">
                                            @foreach ($stackRules as $rule)
                                                <li>{{ $rule }}</li>
                                            @endforeach
                                        </ul>
                                    </section>
                                @endif

                                @if (filled($deleteServiceUrl))
                                    <section class="gc-canvas-details-danger">
                                        <a href="{{ $deleteServiceUrl }}" {{ wireNavigate() }}
                                            class="gc-canvas-details-danger-link">
                                            <x-reicon name="trash" class="size-3.5" />
                                            {{ __('Delete service…') }}
                                        </a>
                                        <p>{{ __('Confirm in Danger Zone. Volumes stay unless you check delete volumes.') }}</p>
                                    </section>
                                @endif
                            </div>
                        @elseif ($inspectorTab === 'logs')
                            <div class="mb-2 flex justify-end">
                                <button type="button" class="button" wire:click="refreshLogs">{{ __('Refresh') }}</button>
                            </div>
                            <pre class="gc-canvas-log">{{ $logOutput }}</pre>
                        @elseif ($inspectorTab === 'metrics')
                            <div class="space-y-3 text-sm">
                                @if ($metricsServer && $metricsEnabled)
                                    <p>
                                        {{ __('Live server metrics from Sentinel (:status).', ['status' => $sentinelStatus ?? '—']) }}
                                    </p>
                                    <div class="gc-canvas-metrics-embed"
                                        wire:key="canvas-metrics-{{ $metricsServer->uuid }}-{{ $inspectorNodeId }}">
                                        <livewire:dashboard.server-metrics-chart :server="$metricsServer"
                                            :key="'canvas-metrics-chart-'.$metricsServer->uuid.'-'.$inspectorNodeId" />
                                    </div>
                                    @if (filled($sentinelUrl))
                                        <p class="text-[12px] text-[var(--dash-dim)]">
                                            <a href="{{ $sentinelUrl }}" class="underline underline-offset-2"
                                                {{ wireNavigate() }}>{{ __('Sentinel settings') }}</a>
                                        </p>
                                    @endif
                                @elseif (filled($monitorEmbedUrl))
                                    <iframe title="{{ __('Monitor') }}" src="{{ $monitorEmbedUrl }}"
                                        class="gc-canvas-inspector-frame" referrerpolicy="no-referrer"></iframe>
                                @else
                                    <p class="text-[var(--dash-dim)]">
                                        {{ __('Enable Sentinel metrics on the server to see CPU and memory here without leaving the canvas.') }}
                                    </p>
                                    @if (filled($sentinelUrl))
                                        <a href="{{ $sentinelUrl }}" class="button" {{ wireNavigate() }}>
                                            {{ __('Sentinel settings') }}
                                        </a>
                                    @endif
                                @endif
                            </div>
                        @endif
                    </div>
                </aside>
            @endif
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
            removeLabel: @js(__('Remove connection')),
            savePosition: (id, x, y) => $wire.savePosition(id, x, y),
            onInspect: (id, panel) => $wire.openInspector(id, panel),
            onConnectNodes: (from, to) => $wire.saveConnection(from, to),
            onDisconnectNodes: (from, to, edgeId) => $wire.removeConnection(from, to, edgeId ?? null),
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
