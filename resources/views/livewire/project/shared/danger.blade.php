<div>
    @php
        $deleteLabel = match ($resource?->type()) {
            'application' => __('Delete application'),
            'service' => __('Delete service'),
            'service-application' => __('Delete service application'),
            'service-database' => __('Delete service database'),
            default => __('Delete resource'),
        };
        $deleteQuestion = match ($resource?->type()) {
            'application' => __('Delete application?'),
            'service' => __('Delete service?'),
            'service-application' => __('Delete service application?'),
            'service-database' => __('Delete service database?'),
            default => __('Delete resource?'),
        };
        $restoreWarning = match ($resource?->type()) {
            'application' => __('This application cannot be restored after deletion.'),
            'service' => __('This service cannot be restored after deletion. Volumes stay on the server unless you opt in below.'),
            'service-application' => __('This service application cannot be restored after deletion.'),
            'service-database' => __('This service database cannot be restored after deletion.'),
            default => __('This resource cannot be restored after deletion.'),
        };
    @endphp

    @if ($resource instanceof \App\Models\Service && ! $resource->server?->isFunctional())
        <x-callout type="warning" title="{{ __('Server is not reachable') }}" class="mb-4">
            {{ __('Coolify cannot remove or verify Docker resources on this server. Prefer “Remove from Coolify only”. Containers and volumes may remain on the server.') }}
        </x-callout>
    @endif

    <x-application.settings-section id="danger-zone-section" title="{{ __('Danger zone') }}"
        helper="{{ __('Destructive resource actions cannot be undone.') }}">
        <x-danger-zone :title="$deleteLabel">
            <p>
                {!! __('Permanently delete :name, stop its containers, and remove the selected Docker resources and configuration.', [
                    'name' => '<strong class="font-semibold text-black dark:text-fg">'.e($resourceName).'</strong>',
                ]) !!}
            </p>
            <ul class="space-y-1 text-xs">
                <li>• {{ __('Active deployments will be stopped.') }}</li>
                <li>• {{ __('Volumes stay alive unless you check “delete volumes”. Orphan volumes remain on the canvas for a separate delete.') }}</li>
                <li>• {{ $restoreWarning }}</li>
            </ul>
            <x-slot:action>
                @if ($canDelete)
                    <div class="flex flex-col gap-2">
                        <x-modal-confirmation :title="$deleteQuestion"
                            :buttonTitle="$deleteLabel"
                            isErrorButton submitAction="delete" :checkboxes="$checkboxes"
                            :actions="[__('Permanently delete this resource and its selected Docker resources.')]"
                            confirmationText="{{ $resourceName }}"
                            confirmationLabel="{{ __('Enter the resource name to confirm permanent deletion') }}"
                            shortConfirmationLabel="{{ __('Resource name') }}" />
                        @if ($resource instanceof \App\Models\Service)
                            <x-modal-confirmation title="{{ __('Remove service from Coolify only?') }}"
                                buttonTitle="{{ __('Remove from Coolify only') }}"
                                isErrorButton submitAction="deleteFromCoolifyOnly"
                                :actions="[
                                    __('Permanently remove this service from Coolify.'),
                                    __('Leave all containers, volumes, networks, and configuration files on the server.'),
                                ]"
                                warningMessage="{{ __('Coolify will no longer track or manage the Docker resources for this service. Use this only when normal cleanup cannot complete.') }}"
                                confirmationText="{{ $resourceName }}"
                                confirmationLabel="{{ __('Enter the resource name to confirm metadata-only deletion') }}"
                                shortConfirmationLabel="{{ __('Resource name') }}" />
                        @endif
                    </div>
                @else
                    <x-forms.button isError disabled tooltip="{{ __('Contact an advisor if you need this change.') }}">
                        {{ $deleteLabel }}
                    </x-forms.button>
                @endif
            </x-slot:action>
        </x-danger-zone>

        @if (! $canDelete)
            <div class="mt-4">
                <x-callout type="info" title="{{ __('Need access?') }}">
                    {{ __('Contact an advisor if you need this change.') }}
                </x-callout>
            </div>
        @endif
    </x-application.settings-section>
</div>
