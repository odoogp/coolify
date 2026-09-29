<div>
    <x-slot:title>
        {{ data_get_str($storage, 'name')->limit(20) }} | S3 Storage | Coolify
    </x-slot>

    @php
        $storageRouteParameters = ['storage_uuid' => $storage->uuid];
        $showSettingsSidebar = in_array($currentRoute, ['storage.show', 'storage.resources', 'storage.danger'], true);
        $settingsMenuItems = [
            [
                'label' => __('General'),
                'route' => 'storage.show',
                'active' => $currentRoute === 'storage.show',
                'icon' => 'settings',
            ],
            [
                'label' => __('Resources'),
                'route' => 'storage.resources',
                'active' => $currentRoute === 'storage.resources',
                'icon' => 'grid',
            ],
            [
                'label' => __('Danger Zone'),
                'route' => 'storage.danger',
                'active' => $currentRoute === 'storage.danger',
                'icon' => 'shield-alert',
            ],
        ];
    @endphp

    <x-dashboard.navbar section="storage" :parameters="$storageRouteParameters"
        :title="$storage->name"
        :subtitle="filled($storage->description) ? $storage->description : 'S3-compatible backup destination'"
        :mobileTitleOnly="true" />

    @if ($showSettingsSidebar)
        <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
            <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
                <aside class="application-settings-navigation min-w-0 xl:self-start">
                    <nav aria-label="{{ __('S3 storage settings') }}"
                        class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
                        <div class="nav-section hidden xl:block">{{ __('Settings') }}</div>
                        @foreach ($settingsMenuItems as $menuItem)
                            <a wire:key="storage-settings-{{ str($menuItem['label'])->slug() }}"
                                @class([
                                    'menu-item',
                                    'menu-item-active' => $menuItem['active'],
                                ])
                                {{ wireNavigate() }}
                                href="{{ route($menuItem['route'], $storageRouteParameters) }}">
                                <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                                <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                            </a>
                        @endforeach
                    </nav>
                </aside>

                <div class="min-w-0">
                    @if ($currentRoute === 'storage.show')
                        <livewire:storage.form :storage="$storage" />
                    @elseif ($currentRoute === 'storage.resources')
                        <livewire:storage.resources :storage="$storage" :key="'resources-'.$storage->uuid" />
                    @elseif ($currentRoute === 'storage.danger')
                        <div class="application-settings-form">
                            <x-application.settings-section id="storage-danger-section" title="{{ __('Danger zone') }}"
                                helper="{{ __('Destructive actions for this S3 storage destination cannot be undone.') }}">
                                <div
                                    class="rounded-lg border border-red-300 bg-red-50 p-4 ring-1 ring-inset ring-red-200/60 dark:border-error/30 dark:bg-error/[0.08] dark:ring-error/10">
                                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h4 class="text-sm font-semibold text-red-700 dark:text-error">{{ __('Delete storage') }}</h4>
                                                <x-status-badge status="Permanent" type="error" />
                                            </div>
                                            <p class="mt-2 max-w-2xl text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">
                                                {{ __('Permanently delete') }}
                                                <strong class="font-semibold text-black dark:text-fg">{{ $storage->name }}</strong>
                                                from Coolify. Existing objects in the bucket are not deleted.
                                            </p>
                                            <ul class="mt-3 space-y-1 text-xs text-neutral-500 dark:text-fg-dim">
                                                <li>• Backup schedules pointing at this storage will stop writing to S3.</li>
                                                @if ($backupCount > 0)
                                                    <li>• {{ $backupCount }} backup schedule(s) currently use this destination.</li>
                                                @endif
                                                <li>• Bucket contents on the provider are left untouched.</li>
                                                <li>• This storage destination cannot be restored from Coolify after deletion.</li>
                                            </ul>
                                        </div>

                                        <div class="shrink-0">
                                            @can('delete', $storage)
                                                <x-modal-confirmation title="{{ __('Confirm Storage Deletion?') }}" isErrorButton
                                                    buttonTitle="Delete" submitAction="delete"
                                                    :actions="array_filter([
                                                        'The selected storage location will be permanently deleted from Coolify.',
                                                        $backupCount > 0
                                                            ? $backupCount.' backup schedule(s) will stop saving to S3. Existing objects in this storage will not be deleted.'
                                                            : null,
                                                    ])"
                                                    confirmationText="{{ $storage->name }}"
                                                    confirmationLabel="{{ __('Please confirm the execution of the actions by entering the Storage Name below') }}"
                                                    shortConfirmationLabel="{{ __('Storage Name') }}" :confirmWithPassword="false"
                                                    step2ButtonText="Permanently Delete" />
                                            @else
                                                <x-forms.button disabled tooltip="You do not have permission to delete this storage.">
                                                    {{ __('Delete') }}
                                                </x-forms.button>
                                            @endcan
                                        </div>
                                    </div>
                                </div>

                                @cannot('delete', $storage)
                                    <div class="mt-4">
                                        <x-callout type="danger" title="{{ __('Insufficient permissions') }}">
                                            {{ __('Contact a team administrator if this storage must be deleted.') }}
                                        </x-callout>
                                    </div>
                                @endcannot
                            </x-application.settings-section>
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @endif
</div>
