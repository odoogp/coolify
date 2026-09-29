<div>
    @php
        $canUpdate = auth()->user()->can('update', $application);
        $labelsManagedByCoolify = $application->settings->is_container_label_readonly_enabled;
        // Use model UUIDs: Livewire update requests do not carry page route params.
        $generalRouteParameters = [
            'project_uuid' => data_get($application, 'environment.project.uuid'),
            'environment_uuid' => data_get($application, 'environment.uuid'),
            'application_uuid' => $application->uuid,
        ];
    @endphp

    <div class="flex flex-col gap-6">
        <x-application.settings-section id="advanced-build-section" title="{{ __('Build') }}"
            helper="{{ __('Fine-tune how images are built for this application.') }}">
            <div class="grid w-full gap-4 sm:grid-cols-2">
                <x-forms.listbox id="disableBuildCache" label="{{ __('Build cache') }}" onChange="instantSave"
                    helper="{{ __('Disabling the cache forces a completely fresh Docker build on every deployment.') }}"
                    :options="[
                        ['value' => false, 'label' => __('Use Docker build cache')],
                        ['value' => true, 'label' => __('Rebuild from scratch every time')],
                    ]" :disabled="! $canUpdate" />
                <x-forms.listbox id="injectBuildArgsToDockerfile" label="{{ __('Build arguments') }}" onChange="instantSave"
                    helper="{{ __('When injected automatically, Coolify adds ARG statements to your Dockerfile for build-time variables. Manage them manually to preserve Docker build cache.') }}"
                    :options="[
                        ['value' => true, 'label' => __('Inject build args automatically')],
                        ['value' => false, 'label' => __('Managed manually in Dockerfile')],
                    ]" :disabled="! $canUpdate" />
                <x-forms.listbox id="includeSourceCommitInBuild" label="{{ __('Source commit availability') }}" onChange="instantSave"
                    helper="{{ __('SOURCE_COMMIT (git commit hash) is always available at runtime. Making it available during build invalidates the cache on every commit.') }}"
                    :options="[
                        ['value' => false, 'label' => __('Runtime only (preserves cache)')],
                        ['value' => true, 'label' => __('Available during build')],
                    ]" :disabled="! $canUpdate" />
            </div>
        </x-application.settings-section>

        <x-application.settings-section id="advanced-container-section" title="{{ __('Container') }}"
            helper="{{ __('Control how the deployed container is named.') }}">
            <div class="grid w-full gap-4 sm:grid-cols-2">
                <x-forms.listbox id="isConsistentContainerNameEnabled" label="{{ __('Container naming') }}" onChange="instantSave"
                    helper="With a consistent name the container is always called {{ $application->uuid }}. <span class='font-bold dark:text-warning'>{{ __('You will lose the rolling update feature!') }}</span>"
                    :options="[
                        ['value' => false, 'label' => __('Generated name (rolling updates)')],
                        ['value' => true, 'label' => __('Consistent name (no rolling updates)')],
                    ]" :disabled="! $canUpdate" />
                @if ($isConsistentContainerNameEnabled === true)
                    <x-forms.input
                        helper="{{ __('You can add a custom name for your container.<br><br>The name is saved automatically and converted to slug format. <span class=\'font-bold dark:text-warning\'>You will lose the rolling update feature!</span>') }}"
                        id="customInternalName" label="{{ __('Custom container name') }}" canGate="update"
                        wire:change="saveCustomName" :canResource="$application" />
                @endif
            </div>
        </x-application.settings-section>

        @if ($application->git_based())
            <x-application.settings-section id="advanced-deployment-section" title="{{ __('Deployment') }}"
                helper="{{ __('Automatic deployments and pull request previews.') }}">
                <div class="grid w-full gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="isAutoDeployEnabled" label="{{ __('Auto deploy') }}" onChange="instantSave"
                        helper="{{ __('Automatically deploy new commits based on Git webhooks.') }}"
                        :options="[
                            ['value' => true, 'label' => __('Deploy on push (webhooks)')],
                            ['value' => false, 'label' => __('Manual deployments only')],
                        ]" :disabled="! $canUpdate" />
                    <x-forms.listbox id="isPreviewDeploymentsEnabled" label="{{ __('Preview deployments') }}" onChange="instantSave"
                        helper="{{ __('Automatically deploy Preview Deployments for all opened PRs.<br><br>Closing a PR deletes its Preview Deployment.') }}"
                        :options="[
                            ['value' => false, 'label' => __('Disabled')],
                            ['value' => true, 'label' => __('Deploy opened pull requests')],
                        ]" :disabled="! $canUpdate" />
                    <x-forms.listbox id="isPrDeploymentsPublicEnabled" label="{{ __('PR deployment access') }}" onChange="instantSave"
                        helper="{{ __('When public, anyone can trigger PR deployments. Otherwise fork PRs are blocked and only repository owners, members, and collaborators can trigger them.') }}"
                        :options="[
                            ['value' => false, 'label' => __('Repository members only')],
                            ['value' => true, 'label' => __('Public (fork PRs allowed)')],
                        ]" :disabled="! $canUpdate || ! $isPreviewDeploymentsEnabled" />
                </div>
            </x-application.settings-section>

            <x-application.settings-section id="advanced-git-section" title="{{ __('Git') }}"
                helper="{{ __('Options applied while cloning the repository during builds.') }}">
                <div class="grid w-full gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="isGitSubmodulesEnabled" label="{{ __('Submodules') }}" onChange="instantSave"
                        helper="{{ __('Allow Git submodules during the build process.') }}"
                        :options="[
                            ['value' => true, 'label' => __('Clone submodules')],
                            ['value' => false, 'label' => __('Skip submodules')],
                        ]" :disabled="! $canUpdate" />
                    <x-forms.listbox id="isGitLfsEnabled" label="{{ __('Git LFS') }}" onChange="instantSave"
                        helper="{{ __('Allow Git LFS during the build process.') }}"
                        :options="[
                            ['value' => true, 'label' => __('Enabled')],
                            ['value' => false, 'label' => __('Disabled')],
                        ]" :disabled="! $canUpdate" />
                    <x-forms.listbox id="isGitShallowCloneEnabled" label="{{ __('Clone depth') }}" onChange="instantSave"
                        helper="{{ __('Shallow cloning (--depth=1) speeds up deployments by only fetching the latest commit, useful for large repositories.') }}"
                        :options="[
                            ['value' => false, 'label' => __('Full history')],
                            ['value' => true, 'label' => __('Shallow clone (latest commit only)')],
                        ]" :disabled="! $canUpdate" />
                </div>
            </x-application.settings-section>
        @endif

        @if ($application->build_pack === 'dockercompose')
            <x-application.settings-section id="advanced-compose-section" title="{{ __('Docker compose') }}"
                helper="{{ __('Advanced behavior for compose-based deployments.') }}">
                <div class="grid w-full gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="isRawComposeDeploymentEnabled" label="{{ __('Compose deployment') }}" onChange="instantSave"
                        helper="WARNING: Advanced use cases only. In raw mode your compose file is deployed as-is. Nothing is modified by Coolify and you need to configure the proxy parts. More info in the <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/compose#raw-docker-compose-deployment'>documentation</a>."
                        :options="[
                            ['value' => false, 'label' => __('Managed by Coolify')],
                            ['value' => true, 'label' => __('Raw (deploy file as-is)')],
                        ]" :disabled="! $canUpdate" />
                    <x-forms.listbox id="isConnectToDockerNetworkEnabled" label="{{ __('Predefined network') }}" onChange="instantSave"
                        helper="By default a compose resource only gets its own internal network. Connecting to a Coolify predefined network may require different internal DNS names. More info <a class='underline dark:text-white' target='_blank' href='https://coolify.io/docs/knowledge-base/docker/compose#connect-to-predefined-networks'>here</a>."
                        :options="[
                            ['value' => false, 'label' => __('Isolated network only')],
                            ['value' => true, 'label' => __('Connect to predefined network')],
                        ]" :disabled="! $canUpdate" />
                </div>
            </x-application.settings-section>
        @endif

        <x-application.settings-section id="advanced-proxy-section" title="{{ __('Proxy') }}"
            helper="{{ __('How the proxy serves traffic for this application.') }}">
            @if ($labelsManagedByCoolify)
                <div class="grid w-full gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="isGzipEnabled" label="{{ __('Gzip compression') }}" onChange="instantSave"
                        helper="{{ __('Some services compress data by default. In that case you do not need this.') }}"
                        :options="[
                            ['value' => true, 'label' => __('Enabled')],
                            ['value' => false, 'label' => __('Disabled')],
                        ]" :disabled="! $canUpdate" />
                    <x-forms.listbox id="isStripprefixEnabled" label="{{ __('Path prefixes') }}" onChange="instantSave"
                        helper="{{ __('Strip Prefix removes prefixes from paths, like /api/ to /.') }}"
                        :options="[
                            ['value' => true, 'label' => __('Strip prefixes')],
                            ['value' => false, 'label' => __('Keep paths as-is')],
                        ]" :disabled="! $canUpdate" />
                </div>
            @else
                <x-empty size="sm" title="{{ __('Proxy behavior is managed through labels') }}"
                    description="{{ __('Container labels are managed manually for this application. Switch label management back to Coolify to configure the proxy here.') }}"
                    icon-name="globe">
                    <x-slot:contents>
                        <a class="button"
                            href="{{ route('project.application.configuration', $generalRouteParameters) }}#container-labels-section"
                            {{ wireNavigate() }}>
                            {{ __('Go to Container labels') }}
                        </a>
                    </x-slot:contents>
                </x-empty>
            @endif
        </x-application.settings-section>

        <x-application.settings-section id="advanced-operations-section" title="{{ __('Operations') }}"
            helper="{{ __('Shutdown and restart behavior for this application\'s containers.') }}">
            <div class="grid w-full gap-4 lg:grid-cols-2">
                <x-forms.input type="number" id="stopGracePeriod" label="{{ __('Stop grace period (seconds)') }}"
                    placeholder="{{ DEFAULT_STOP_GRACE_PERIOD_SECONDS }}" wire:change="saveStopGracePeriod"
                    helper="How long to wait for graceful shutdown during rolling updates, manual stops, and restarts. Applies to all containers for this application. Saved automatically. Default: {{ DEFAULT_STOP_GRACE_PERIOD_SECONDS }} seconds. Range: {{ MIN_STOP_GRACE_PERIOD_SECONDS }}-{{ MAX_STOP_GRACE_PERIOD_SECONDS }} seconds (1 hour)."
                    min="{{ MIN_STOP_GRACE_PERIOD_SECONDS }}" max="{{ MAX_STOP_GRACE_PERIOD_SECONDS }}"
                    canGate="update" :canResource="$application" />
                <x-forms.input type="number" min="0" id="maxRestartCount" label="{{ __('Max restart count') }}"
                    wire:change="saveMaxRestartCount"
                    helper="{{ __('Maximum number of crash restarts before Coolify automatically stops the application and sends a notification. Saved automatically. Set to 0 to disable the limit.') }}"
                    canGate="update" :canResource="$application" />
            </div>
        </x-application.settings-section>

        <x-application.settings-section id="advanced-logs-section" title="{{ __('Logs') }}"
            helper="{{ __('Forward container logs to an external endpoint.') }}">
            <div class="grid w-full gap-4 sm:grid-cols-2">
                <x-forms.listbox id="isLogDrainEnabled" label="{{ __('Log drain') }}" onChange="instantSave"
                    helper="{{ __('Drain logs to the log drain endpoint configured in your Server settings.') }}"
                    :options="[
                        ['value' => false, 'label' => __('Disabled')],
                        ['value' => true, 'label' => __('Send logs to the log drain endpoint')],
                    ]" :disabled="! $canUpdate" />
            </div>
        </x-application.settings-section>

        @if ($application->build_pack !== 'dockercompose')
            <x-application.settings-section id="advanced-gpu-section" title="{{ __('GPU') }}"
                helper="Give this application access to the host's GPUs. More info <a href='https://docs.docker.com/compose/gpu-support/' class='underline dark:text-white' target='_blank'>here</a>.">
                <div class="grid w-full gap-4 sm:grid-cols-2">
                    <x-forms.listbox id="isGpuEnabled" label="{{ __('GPU access') }}" onChange="instantSave"
                        :options="[
                            ['value' => false, 'label' => __('Disabled')],
                            ['value' => true, 'label' => __('Enabled')],
                        ]" :disabled="! $canUpdate" />
                </div>
                @if ($isGpuEnabled)
                    <form id="gpu-settings-form" wire:submit="submit"
                        class="mt-5 flex w-full flex-col gap-4 border-t border-neutral-200 pt-5 dark:border-white/[0.07]">
                        {{-- Scope to GPU form fields; sibling instantSave listboxes share this component. --}}
                        <x-unsaved-bar action="submit"
                            targets="gpuDriver,gpuCount,gpuDeviceIds,gpuOptions" />
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-forms.input label="{{ __('GPU driver') }}" id="gpuDriver" canGate="update" :canResource="$application" />
                            <x-forms.input label="{{ __('GPU count') }}" placeholder="{{ __('Empty means use all GPUs') }}" id="gpuCount"
                                canGate="update" :canResource="$application" />
                        </div>
                        <x-forms.input label="{{ __('GPU device ids') }}" placeholder="0,2"
                            helper="Comma separated list of device ids. More info <a href='https://docs.docker.com/compose/gpu-support/#access-specific-devices' class='underline dark:text-white' target='_blank'>here</a>."
                            id="gpuDeviceIds" canGate="update" :canResource="$application" />
                        <x-forms.textarea rows="6" label="{{ __('GPU options') }}" id="gpuOptions" canGate="update"
                            :canResource="$application" />
                    </form>
                @endif
            </x-application.settings-section>
        @endif
    </div>
</div>
