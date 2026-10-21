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
        </div>
    </section>
</div>
