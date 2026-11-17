<div>
    <x-slot:title>
        {{ __('Deployments') }} | {{ data_get_str($service, 'name')->limit(10) }} | {{ product_name() }}
    </x-slot>

    <livewire:project.service.heading :service="$service" :parameters="[
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'service_uuid' => $service->uuid,
    ]" :query="request()->query()" wire:key="service-heading-deployments" />

    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
            <x-service.configuration-sidebar :service="$service" current-route="project.service.deployments" />

            <div class="application-settings-form min-w-0">
                @if ($isOdooService)
                    <div class="gc-odoo-hub">
                        @if ($hasDraft && $canUpdate)
                            <div class="gc-review-banner" role="status">
                                <div class="gc-review-banner-copy">
                                    <strong>{{ trans_choice(':count change in review|:count changes in review', count($draftDiff), ['count' => count($draftDiff)]) }}</strong>
                                    <span>{{ __('Nothing is applied until you confirm.') }}</span>
                                </div>
                                <div class="gc-review-banner-actions">
                                    <button type="button" class="gc-review-btn" wire:click="toggleDiff">
                                        {{ $showDiff ? __('Hide diff') : __('View diff') }}
                                    </button>
                                    <button type="button" class="gc-review-btn" wire:click="discardDraft" wire:confirm="{{ __('Discard these draft changes?') }}">
                                        {{ __('Discard') }}
                                    </button>
                                    <button type="button" class="button button-highlighted" wire:click="applyDraft">
                                        {{ __('Apply') }}
                                    </button>
                                </div>
                            </div>
                            @if ($showDiff)
                                <div class="gc-review-diff">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>{{ __('Field') }}</th>
                                                <th>{{ __('Current') }}</th>
                                                <th>{{ __('Draft') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($draftDiff as $row)
                                                <tr wire:key="diff-{{ $row['field'] }}">
                                                    <td>{{ $row['field'] }}</td>
                                                    <td><code>{{ $row['from'] }}</code></td>
                                                    <td><code>{{ $row['to'] }}</code></td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        @endif

                        <div class="gc-segmented gc-odoo-hub-tabs" role="tablist" aria-label="{{ __('Service hub') }}">
                            <button type="button" role="tab" class="gc-segmented-item {{ $tab === 'overview' ? 'is-active' : '' }}"
                                wire:click="setTab('overview')" aria-selected="{{ $tab === 'overview' ? 'true' : 'false' }}">
                                {{ __('Overview') }}
                            </button>
                            <button type="button" role="tab" class="gc-segmented-item {{ $tab === 'deploys' ? 'is-active' : '' }}"
                                wire:click="setTab('deploys')" aria-selected="{{ $tab === 'deploys' ? 'true' : 'false' }}">
                                {{ __('Deploys') }}
                            </button>
                            <button type="button" role="tab" class="gc-segmented-item {{ $tab === 'runtime' ? 'is-active' : '' }}"
                                wire:click="setTab('runtime')" aria-selected="{{ $tab === 'runtime' ? 'true' : 'false' }}">
                                {{ __('Runtime') }}
                                @if ($hasDraft)
                                    <span class="gc-segmented-count is-alert">{{ count($draftDiff) }}</span>
                                @endif
                            </button>
                        </div>

                        @if ($tab === 'overview')
                            <div class="gc-odoo-hub-panel" wire:key="hub-overview">
                                <div class="gc-odoo-hub-hero">
                                    <div>
                                        <p class="gc-odoo-hub-kicker">{{ $environment->name }} · {{ __('Odoo') }}</p>
                                        <h2 class="gc-odoo-hub-title">{{ $service->name }}</h2>
                                        <p class="gc-odoo-hub-status">{{ $service->status ?? __('Unknown') }}</p>
                                    </div>
                                    <div class="gc-odoo-hub-actions">
                                        @if ($canOpen && filled($openUrl))
                                            <a href="{{ $openUrl }}" target="_blank" rel="noopener" class="button button-highlighted">
                                                {{ __('Open Odoo') }}
                                            </a>
                                        @endif
                                        <button type="button" class="button" wire:click="setTab('deploys')">
                                            {{ __('View history') }}
                                        </button>
                                        <a href="{{ route('project.environment.canvas', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}"
                                            {{ wireNavigate() }} class="button">
                                            {{ __('Canvas') }}
                                        </a>
                                    </div>
                                </div>

                                <x-application.settings-section title="{{ __('Latest update') }}"
                                    helper="{{ __('Git push to sync modules, restart Odoo, and mark the environment running.') }}">
                                    <livewire:project.service.deploy-pipeline :service="$service"
                                        :application="$addonsApplication"
                                        wire:key="odoo-pipeline-{{ $service->uuid }}" />
                                </x-application.settings-section>
                            </div>
                        @elseif ($tab === 'deploys')
                            <div class="gc-odoo-hub-panel" wire:key="hub-deploys">
                                @if ($addonsApplication)
                                    <livewire:project.application.deployment.index
                                        :embedded="true"
                                        :force-page-size="true"
                                        :default-take="5"
                                        :application-uuid="$addonsApplication->uuid"
                                        wire:key="service-deployments-{{ $addonsApplication->uuid }}" />
                                @else
                                    <x-application.settings-section title="{{ __('Deployment history') }}"
                                        helper="{{ __('Connect a GitHub branch to this environment to track module updates here.') }}">
                                        <x-empty size="sm" title="{{ __('No deployments yet') }}"
                                            description="{{ __('Link a repository and branch on GitHub, then push to create the first update.') }}"
                                            icon-name="layers" />
                                    </x-application.settings-section>
                                @endif
                            </div>
                        @else
                            <div class="gc-odoo-hub-panel" wire:key="hub-runtime">
                                <x-application.settings-section title="{{ __('Environment address') }}"
                                    helper="{{ __('Edits stay in review until you Apply. Applying may restart Odoo when the public address changes.') }}">
                                    <div class="flex flex-col gap-4 max-w-xl">
                                        <x-forms.input canGate="update" :canResource="$service" id="draftSubdomain"
                                            label="{{ __('Subdomain') }}"
                                            helper="{{ filled($odooBaseDomain) ? __('Public host under :domain', ['domain' => $odooBaseDomain]) : __('Public host under the instance Odoo base domain') }}"
                                            wire:model.live.debounce.400ms="draftSubdomain" />
                                        <x-forms.input canGate="update" :canResource="$service" id="draftWorkers"
                                            type="number" min="0" max="32" label="{{ __('Workers') }}"
                                            helper="{{ __('Applied on the next start unless the address also changes.') }}"
                                            wire:model.live.debounce.400ms="draftWorkers" />
                                        @if ($hasDraft)
                                            <p class="text-xs text-neutral-500 dark:text-neutral-400">
                                                {{ __('Draft saved. Use Apply in the banner to publish these values.') }}
                                            </p>
                                        @endif
                                    </div>
                                </x-application.settings-section>
                            </div>
                        @endif
                    </div>
                @else
                    @if ($addonsApplication)
                        <livewire:project.application.deployment.index
                            :embedded="true"
                            :force-page-size="true"
                            :default-take="5"
                            :application-uuid="$addonsApplication->uuid"
                            wire:key="service-deployments-{{ $addonsApplication->uuid }}" />
                    @else
                        <x-application.settings-section title="{{ __('Deployment history') }}"
                            helper="{{ __('Connect a GitHub branch to this environment to track module updates here.') }}">
                            <x-empty size="sm" title="{{ __('No deployments yet') }}"
                                description="{{ __('Link a repository and branch on GitHub, then push to create the first update.') }}"
                                icon-name="layers" />
                        </x-application.settings-section>
                    @endif
                @endif
            </div>
        </div>
    </section>
</div>
