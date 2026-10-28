<div>
    <x-slot:title>
        {{ __('Keys') }} | {{ data_get_str($project, 'name')->limit(10) }} | {{ product_name() }}
    </x-slot>

    @if ($service)
        <livewire:project.service.heading :service="$service" :parameters="[
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'service_uuid' => $service->uuid,
        ]" :query="request()->query()" wire:key="service-heading-environment-keys" />
    @endif

    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
            @if ($service)
                <x-service.configuration-sidebar :service="$service" current-route="project.environment.keys" />
            @endif

            <div class="application-settings-form min-w-0 flex flex-col gap-6">
                <x-application.settings-section title="{{ __('Environment keys') }}"
                    helper="{{ __('Login credentials for services in this environment. Changing the Odoo administrator password here updates Coolify and applies it in Odoo — no current password required.') }}">
                    @if ($cards === [])
                        <p class="text-sm text-neutral-500 dark:text-fg-dim">
                            {{ __('No login credentials yet for this environment.') }}
                        </p>
                    @else
                        <div class="flex flex-col gap-6">
                            @foreach ($cards as $card)
                                <div
                                    class="rounded-xl border border-neutral-200 bg-neutral-50/80 p-4 dark:border-white/[0.08] dark:bg-white/[0.025]">
                                    <div class="mb-3 flex items-center gap-2">
                                        <x-reicon name="keys" class="size-4 shrink-0 text-neutral-500 dark:text-fg-dim" />
                                        <div class="min-w-0">
                                            <p class="text-[13px] font-medium text-neutral-900 dark:text-fg">{{ $card['title'] }}</p>
                                            <p class="text-[12px] text-neutral-500 dark:text-fg-dim">{{ $card['service_name'] }}</p>
                                        </div>
                                    </div>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        @foreach ($card['credentials'] as $credential)
                                            <div>
                                                <p class="auth-plan-kicker mb-1">{{ $credential['label'] }}</p>
                                                @if ($passwordsHidden && in_array($credential['kind'], ['password', 'token'], true))
                                                    <x-forms.input disabled value="{{ __('Hidden (only admins can view)') }}" />
                                                @elseif (in_array($credential['kind'], ['password', 'token'], true))
                                                    <x-forms.input type="password" readonly
                                                        value="{{ $credential['value'] !== '' ? $credential['value'] : __('Not set') }}" />
                                                @else
                                                    <x-forms.input readonly value="{{ $credential['value'] !== '' ? $credential['value'] : __('Not set') }}" />
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>

                                    @if ($card['kind'] === 'odoo' && $canEditOdooPassword)
                                        <form wire:submit="saveOdooAdminPassword" class="mt-4 flex flex-col gap-3 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                                            <p class="text-[13px] text-neutral-600 dark:text-fg-dim">
                                                {{ __('Set a new administrator password. Coolify stores it and writes it into Odoo for login admin.') }}
                                            </p>
                                            <div class="grid gap-3 sm:grid-cols-2">
                                                <x-forms.input type="password" id="odooAdminPassword"
                                                    label="{{ __('New password') }}" autocomplete="new-password" />
                                                <x-forms.input type="password" id="odooAdminPasswordConfirmation"
                                                    label="{{ __('Confirm password') }}" autocomplete="new-password" />
                                            </div>
                                            <div>
                                                <x-forms.button type="submit" canGate="update" :canResource="$service" isHighlighted>
                                                    {{ __('Update administrator password') }}
                                                </x-forms.button>
                                            </div>
                                        </form>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-application.settings-section>
            </div>
        </div>
    </section>
</div>
