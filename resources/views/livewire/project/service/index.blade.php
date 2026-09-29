<div>
    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="$query" />
    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        @if ($resourceType === 'database')
            <x-service-database.sidebar :parameters="$parameters" :serviceDatabase="$serviceDatabase" :isImportSupported="$isImportSupported" />
        @else
            <aside class="application-settings-navigation min-w-0 xl:self-start">
                <nav aria-label="{{ __('Compose resource settings') }}"
                    class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
                    <div class="nav-section hidden xl:block">{{ __('Compose resource') }}</div>
                <a class="menu-item" {{ wireNavigate() }}
                    href="{{ route('project.service.configuration', [...$parameters, 'stack_service_uuid' => null]) }}">
                    <x-reicon name="logout" class="menu-item-icon rotate-180" />
                    <span class="menu-item-label">{{ __('Back to service') }}</span>
                </a>
                <a @class(['menu-item', 'menu-item-active' => request()->routeIs('project.service.index')])
                    {{ wireNavigate() }} href="{{ route('project.service.index', $parameters) }}">
                    <x-reicon name="settings" class="menu-item-icon" />
                    <span class="menu-item-label">{{ __('General') }}</span>
                </a>
                <a @class(['menu-item', 'menu-item-active' => request()->routeIs('project.service.index.advanced')])
                    {{ wireNavigate() }} href="{{ route('project.service.index.advanced', $parameters) }}">
                    <x-reicon name="grid" class="menu-item-icon" />
                    <span class="menu-item-label">{{ __('Advanced') }}</span>
                </a>
                </nav>
            </aside>
        @endif
        <div class="min-w-0">
            @if ($resourceType === 'application')
                <x-slot:title>
                    {{ data_get_str($service, 'name')->limit(10) }} >
                    {{ data_get_str($serviceApplication, 'name')->limit(10) }} | Coolify
                </x-slot>
                @if ($currentRoute === 'project.service.index.advanced')
                    <section class="application-settings-section">
                        <div class="application-settings-section-header">
                            <div>
                                <h2>{{ __('Advanced') }}</h2>
                                <p>{{ __('Control proxy, status, and logging behavior for this compose resource.') }}</p>
                            </div>
                        </div>
                        <div class="application-settings-section-body grid gap-4 sm:grid-cols-2">
                        @if (str($serviceApplication->image)->contains('pocketbase'))
                            <x-forms.listbox id="isGzipEnabled" label="{{ __('Gzip compression') }}"
                                helper="{{ __('PocketBase keeps compression disabled so server-sent events continue to work.') }}"
                                :disabled="true" :options="[
                                    ['value' => true, 'label' => __('Enabled')],
                                    ['value' => false, 'label' => __('Disabled')],
                                ]" />
                        @else
                            <x-forms.listbox id="isGzipEnabled" label="{{ __('Gzip compression') }}"
                                onChange="instantSaveApplicationSettings" :options="[
                                    ['value' => true, 'label' => __('Enabled')],
                                    ['value' => false, 'label' => __('Disabled')],
                                ]" />
                        @endif
                        <x-forms.listbox id="isStripprefixEnabled" label="{{ __('Path prefixes') }}"
                            onChange="instantSaveApplicationSettings" :options="[
                                ['value' => true, 'label' => __('Strip prefixes')],
                                ['value' => false, 'label' => __('Keep prefixes')],
                            ]" />
                        <x-forms.listbox id="excludeFromStatus" label="{{ __('Service status') }}"
                            onChange="instantSaveApplicationSettings" :options="[
                                ['value' => false, 'label' => __('Include in status')],
                                ['value' => true, 'label' => __('Exclude from status')],
                            ]" />
                        <x-forms.listbox id="isLogDrainEnabled" label="{{ __('Log drain') }}"
                            onChange="instantSaveApplicationAdvanced" :options="[
                                ['value' => true, 'label' => __('Send logs to drain')],
                                ['value' => false, 'label' => __('Do not drain logs')],
                            ]" />
                        </div>
                    </section>
                @else
                    <form wire:submit="submitApplication" class="space-y-6">
                        <x-unsaved-bar action="submitApplication" />
                        <section class="application-settings-section">
                            <div class="application-settings-section-header">
                                <div>
                                    <h2>{{ Str::headline($serviceApplication->human_name ?: $serviceApplication->name) }}</h2>
                                    <p>{{ __('Identity, image, and public access for this compose application.') }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                            @can('update', $serviceApplication)
                                <x-modal-confirmation wire:click="convertToDatabase" title="{{ __('Convert to Database') }}"
                                    buttonTitle="Convert to Database" submitAction="convertToDatabase" :actions="['The selected resource will be converted to a service database.']"
                                    confirmationText="{{ Str::headline($serviceApplication->name) }}"
                                    confirmationLabel="{{ __('Please confirm the execution of the actions by entering the Service Application Name below') }}"
                                    shortConfirmationLabel="{{ __('Service Application Name') }}" />
                            @endcan
                            @can('delete', $serviceApplication)
                                <x-modal-confirmation title="{{ __('Confirm Service Application Deletion?') }}" buttonTitle="Delete" isErrorButton
                                    submitAction="deleteApplication" :actions="['The selected service application container will be stopped and permanently deleted.']"
                                    confirmationText="{{ Str::headline($serviceApplication->name) }}"
                                    confirmationLabel="{{ __('Please confirm the execution of the actions by entering the Service Application Name below') }}"
                                    shortConfirmationLabel="{{ __('Service Application Name') }}" />
                            @endcan
                                </div>
                            </div>
                            <div class="application-settings-section-body space-y-4">
                            @if ($requiredPort && !$serviceApplication->serviceType()?->contains(str($serviceApplication->image)->before(':')))
                                <x-callout type="info" title="Required Port: {{ $requiredPort }}" class="mb-2">
                                    This service requires port <strong>{{ $requiredPort }}</strong> to function correctly. All domains must include this port number (or any other port if you know what you're doing).
                                    <br><br>
                                    <strong>{{ __('Example:') }}</strong> https://app.coolify.io:{{ $requiredPort }},https://www.app.coolify.io:{{ $requiredPort }}
                                </x-callout>
                            @endif

                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-forms.input canGate="update" :canResource="$serviceApplication" label="{{ __('Name') }}" id="humanName"
                                    placeholder="{{ __('Human readable name') }}"></x-forms.input>
                                <x-forms.input canGate="update" :canResource="$serviceApplication" label="{{ __('Description') }}"
                                    id="description"></x-forms.input>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                @if (!$serviceApplication->serviceType()?->contains(str($serviceApplication->image)->before(':')))
                                    <div class="rounded-lg border border-neutral-200 p-4 dark:border-white/[0.08]">
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                            <p class="text-sm text-neutral-500 dark:text-fg-dim">
                                                @php($domainCount = countDomains($fqdn))
                                                @if ($domainCount === 0)
                                                    {{ __('No domains set.') }}
                                                @elseif ($domainCount === 1)
                                                    1 domain set.
                                                @else
                                                    {{ $domainCount }} domains set.
                                                @endif
                                            </p>
                                            <a class="button shrink-0" href="{{ route('project.service.domains', $parameters) }}"
                                                {{ wireNavigate() }}>
                                                <x-reicon name="globe" class="size-4" />
                                                {{ __('Manage domains') }}
                                            </a>
                                        </div>
                                    </div>
                                @endif
                                <x-forms.input canGate="update" :canResource="$serviceApplication"
                                    helper="{{ __('You can change the image you would like to deploy.<br><br><span class=\'dark:text-warning\'>WARNING. You could corrupt your data. Only do it if you know what you are doing.</span>') }}"
                                    label="{{ __('Image') }}" id="image"></x-forms.input>
                            </div>
                            </div>
                        </section>
                    </form>

                    <x-domain-conflict-modal
                        :conflicts="$domainConflicts"
                        :showModal="$showDomainConflictModal"
                        confirmAction="confirmDomainUsage">
                        <x-slot:consequences>
                            <ul class="mt-2 ml-4 list-disc">
                                <li>{{ __('Only one service will be accessible at this domain') }}</li>
                                <li>{{ __('The routing behavior will be unpredictable') }}</li>
                                <li>{{ __('You may experience service disruptions') }}</li>
                                <li>{{ __('SSL certificates might not work correctly') }}</li>
                            </ul>
                        </x-slot:consequences>
                    </x-domain-conflict-modal>

                    @if ($showPortWarningModal)
                        <div x-data="{ modalOpen: true }" x-init="$nextTick(() => { modalOpen = true })"
                            @keydown.escape.window="modalOpen = false; $wire.call('cancelRemovePort')"
                            :class="{ 'z-40': modalOpen }" class="relative">
                            <template x-teleport="body">
                                <div x-show="modalOpen"
                                    class="fixed inset-0 z-99 flex min-h-full items-center justify-center overflow-y-auto p-4" x-cloak>
                                    <div x-show="modalOpen" class="absolute inset-0 bg-black/50 backdrop-blur-[2px]"></div>
                                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-100"
                                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                                        x-transition:leave="ease-in duration-100"
                                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                                        class="application-settings-form application-settings-section relative w-full lg:min-w-[36rem] lg:max-w-2xl"
                                        style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                                        <header>
                                            <h3>{{ __('Remove required port?') }}</h3>
                                            <button @click="modalOpen = false; $wire.call('cancelRemovePort')"
                                                class="flex size-7 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                                                <x-reicon name="x" class="size-4" />
                                            </button>
                                        </header>
                                        <div class="application-settings-section-body">
                                            <x-callout type="warning" title="{{ __('Port requirement') }}" class="mb-4">
                                                This service requires port <strong>{{ $requiredPort }}</strong> to function correctly.
                                                {{ __('One or more of your domains are missing a port number.') }}
                                            </x-callout>

                                            <x-callout type="danger" title="{{ __('What will happen if you continue?') }}" class="mb-4">
                                                <ul class="mt-2 ml-4 list-disc">
                                                    <li>{{ __('The service may become unreachable') }}</li>
                                                    <li>{{ __('The proxy may not be able to route traffic correctly') }}</li>
                                                    <li>{{ __('Environment variables may not be generated properly') }}</li>
                                                    <li>{{ __('The service may fail to start or function') }}</li>
                                                </ul>
                                            </x-callout>

                                            <div class="mt-4 flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                                                <x-forms.button @click="modalOpen = false; $wire.call('cancelRemovePort')"
                                                    class="w-auto">
                                                    {{ __('Keep port') }}
                                                </x-forms.button>
                                                <x-forms.button wire:click="confirmRemovePort" @click="modalOpen = false" class="w-auto"
                                                    isError>
                                                    {{ __('Remove port anyway') }}
                                                </x-forms.button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    @endif
                @endif
            @elseif ($resourceType === 'database')
                <x-slot:title>
                    {{ data_get_str($service, 'name')->limit(10) }} >
                    {{ data_get_str($serviceDatabase, 'name')->limit(10) }} | Coolify
                </x-slot>
                @if ($currentRoute === 'project.service.database.import')
                    <livewire:project.database.import :resource="$serviceDatabase" :key="'import-' . $serviceDatabase->uuid" />
                @elseif ($currentRoute === 'project.service.index.advanced')
                    <section class="application-settings-section">
                        <div class="application-settings-section-header">
                            <div>
                                <h2>{{ __('Advanced') }}</h2>
                                <p>{{ __('Control status aggregation and external log delivery.') }}</p>
                            </div>
                        </div>
                        <div class="application-settings-section-body grid gap-4 sm:grid-cols-2">
                            <x-forms.listbox id="excludeFromStatus" label="{{ __('Service status') }}"
                                onChange="instantSaveExclude" :options="[
                                    ['value' => false, 'label' => __('Include in status')],
                                    ['value' => true, 'label' => __('Exclude from status')],
                                ]" />
                            <x-forms.listbox id="isLogDrainEnabled" label="{{ __('Log drain') }}"
                                onChange="instantSaveLogDrain" :options="[
                                    ['value' => true, 'label' => __('Send logs to drain')],
                                    ['value' => false, 'label' => __('Do not drain logs')],
                                ]" />
                        </div>
                    </section>
                @else
                    <form wire:submit="submitDatabase" class="space-y-6">
                        <x-unsaved-bar action="submitDatabase" />
                        <section class="application-settings-section">
                            <div class="application-settings-section-header">
                                <div>
                                    <h2>{{ Str::headline($serviceDatabase->human_name ?: $serviceDatabase->name) }}</h2>
                                    <p>{{ __('Identity, image, and public access for this compose database.') }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                            @can('update', $serviceDatabase)
                                <x-modal-confirmation wire:click="convertToApplication" title="{{ __('Convert to Application') }}"
                                    buttonTitle="Convert to Application" submitAction="convertToApplication" :actions="['The selected resource will be converted to an application.']"
                                    confirmationText="{{ Str::headline($serviceDatabase->name) }}"
                                    confirmationLabel="{{ __('Please confirm the execution of the actions by entering the Service Database Name below') }}"
                                    shortConfirmationLabel="{{ __('Service Database Name') }}" />
                            @endcan
                            @can('delete', $serviceDatabase)
                                <x-modal-confirmation title="{{ __('Confirm Service Database Deletion?') }}" buttonTitle="Delete"
                                    isErrorButton submitAction="deleteDatabase" :actions="[
                                        'The selected service database container will be stopped and permanently deleted.',
                                    ]"
                                    confirmationText="{{ Str::headline($serviceDatabase->name) }}"
                                    confirmationLabel="{{ __('Please confirm the execution of the actions by entering the Service Database Name below') }}"
                                    shortConfirmationLabel="{{ __('Service Database Name') }}" />
                            @endcan
                                </div>
                            </div>
                            <div class="application-settings-section-body space-y-5">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-forms.input canGate="update" :canResource="$serviceDatabase" label="{{ __('Name') }}" id="humanName"
                                    placeholder="{{ __('Name') }}"></x-forms.input>
                                <x-forms.input canGate="update" :canResource="$serviceDatabase" label="{{ __('Description') }}"
                                    id="description"></x-forms.input>
                                <x-forms.input class="sm:col-span-2" canGate="update" :canResource="$serviceDatabase" required
                                    helper="{{ __('You can change the image you would like to deploy.<br><br><span class=\'dark:text-warning\'>WARNING. You could corrupt your data. Only do it if you know what you are doing.</span>') }}"
                                    label="{{ __('Image') }}" id="image"></x-forms.input>
                            </div>
                            <div class="border-t border-neutral-200 pt-5 dark:border-white/[0.06]">
                                <div class="mb-4 flex items-center justify-between gap-2">
                                    <h3 class="text-sm font-semibold text-black dark:text-fg">{{ __('Public access') }}</h3>
                                    <div class="flex items-center gap-2">
                                        <x-loading wire:loading wire:target="instantSave" />
                                        @if ($serviceDatabase->is_public)
                                            <x-process-dialog closeWithX size="xl">
                                                <x-slot:title>Proxy Logs</x-slot:title>
                                                <x-slot:content>
                                                    <livewire:project.shared.get-logs :server="$server" :resource="$service"
                                                        :servicesubtype="$serviceDatabase" container="{{ $serviceDatabase->uuid }}-proxy" :collapsible="false" lazy />
                                                </x-slot:content>
                                                <x-forms.button @click="processDialogOpen = true">Logs</x-forms.button>
                                            </x-process-dialog>
                                        @endif
                                    </div>
                                </div>
                                <div class="space-y-4">
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end"
                                        x-data="{ port: @js(filled($publicPort) ? (string) $publicPort : '') }"
                                        @input="if ($event.target.matches('input[type=number], input:not([type])')) port = $event.target.value">
                                        <div class="w-full sm:max-w-xs">
                                            <x-forms.input type="number" canGate="update" :canResource="$serviceDatabase"
                                                placeholder="5432" disabled="{{ $isPublic }}" id="publicPort"
                                                label="{{ __('Public Port') }}" />
                                        </div>
                                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                                            @if ($isPublic)
                                                <x-status-badge status="Public" type="success" />
                                                <x-forms.button canGate="update" :canResource="$serviceDatabase"
                                                    wire:click="disablePublicAccess">
                                                    {{ __('Make private') }}
                                                </x-forms.button>
                                            @else
                                                {{-- Do not nest @if/@endif inside an <x-*> opening tag: Blade component
                                                     compilation breaks and yields "unexpected token endif". --}}
                                                <x-forms.button canGate="update" :canResource="$serviceDatabase"
                                                    wire:click="enablePublicAccess"
                                                    x-bind:disabled="!String(port ?? '').trim()">
                                                    {{ __('Make publicly available') }}
                                                </x-forms.button>
                                            @endif
                                        </div>
                                    </div>
                                    @if ($db_url_public)
                                        <x-forms.input label="{{ __('Database IP:PORT (public)') }}"
                                            helper="{{ __('Your credentials are available in your environment variables.') }}" type="password"
                                            readonly wire:model="db_url_public" />
                                    @endif
                                </div>
                            </div>
                            </div>
                        </section>
                    </form>
                @endif
            @endif
        </div>
        </div>
    </section>
</div>
