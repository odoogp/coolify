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
            'service' => __('This service cannot be restored after deletion.'),
            'service-application' => __('This service application cannot be restored after deletion.'),
            'service-database' => __('This service database cannot be restored after deletion.'),
            default => __('This resource cannot be restored after deletion.'),
        };
    @endphp
    <x-application.settings-section id="danger-zone-section" title="{{ __('Danger zone') }}"
        helper="{{ __('Destructive resource actions cannot be undone.') }}">
        <div
            class="rounded-lg border border-red-300 bg-red-50 p-4 ring-1 ring-inset ring-red-200/60 dark:border-error/30 dark:bg-error/[0.08] dark:ring-error/10">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h4 class="text-sm font-semibold text-red-700 dark:text-error">{{ $deleteLabel }}</h4>
                        <x-status-badge status="Permanent" type="error" />
                    </div>
                    <p class="mt-2 max-w-2xl text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">
                        {!! __('Permanently delete :name, stop its containers, and remove the selected Docker resources and configuration.', [
                            'name' => '<strong class="font-semibold text-black dark:text-fg">'.e($resourceName).'</strong>',
                        ]) !!}
                    </p>
                    <ul class="mt-3 space-y-1 text-xs text-neutral-500 dark:text-fg-dim">
                        <li>• {{ __('Active deployments will be stopped.') }}</li>
                        <li>• {{ __('Selected volumes and stored data may be permanently removed.') }}</li>
                        <li>• {{ $restoreWarning }}</li>
                    </ul>
                </div>

                <div class="shrink-0">
                    @if ($canDelete)
                        <x-modal-confirmation :title="$deleteQuestion"
                            :buttonTitle="$deleteLabel"
                            isErrorButton submitAction="delete" :checkboxes="$checkboxes"
                            :actions="[__('Permanently delete this resource and its selected Docker resources.')]"
                            confirmationText="{{ $resourceName }}"
                            confirmationLabel="{{ __('Enter the resource name to confirm permanent deletion') }}"
                            shortConfirmationLabel="{{ __('Resource name') }}" />
                    @else
                        <x-forms.button disabled tooltip="{{ __('Contact an advisor if you need this change.') }}">
                            {{ $deleteLabel }}
                        </x-forms.button>
                    @endif
                </div>
            </div>
        </div>

        @if (!$canDelete)
            <div class="mt-4">
                <x-callout type="info" title="{{ __('Need access?') }}">
                    {{ __('Contact an advisor if you need this change.') }}
                </x-callout>
            </div>
        @endif
    </x-application.settings-section>
</div>
