<div>
    <x-slot:title>
        {{ __('Regions') }} | {{ product_name() }}
    </x-slot>

    <x-settings.layout>
        <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">{{ __('Regions') }}</h1>
                    <p class="mt-1 max-w-2xl text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                        {{ __('Group countries into a region. On Plans, attach a region so one plan covers every country in it. Country plans still win over region plans for that country.') }}
                    </p>
                </div>
                @unless ($showRegionEditor)
                    <x-forms.button type="button" wire:click="newRegion" isHighlighted>
                        {{ __('New') }}
                    </x-forms.button>
                @endunless
            </div>

            @if ($showRegionEditor)
                <x-application.settings-section :title="$regionId ? __('Edit region') : __('New region')">
                    <form class="flex flex-col gap-4" wire:submit="saveRegion">
                        <x-forms.input id="regionName" required label="{{ __('Region name') }}" />
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                            <div class="min-w-0 flex-1">
                                <x-forms.searchable-listbox id="pendingRegionCountry" portal live
                                    label="{{ __('Add country to region') }}"
                                    searchPlaceholder="{{ __('Search countries') }}"
                                    emptyText="{{ __('No matching country') }}"
                                    :options="$addRegionCountryChoices" />
                            </div>
                            <x-forms.button type="button" wire:click="addRegionCountry">{{ __('Add') }}</x-forms.button>
                        </div>
                        @error('pendingRegionCountry')
                            <p class="text-sm text-red-500">{{ $message }}</p>
                        @enderror
                        @error('regionCountries')
                            <p class="text-sm text-red-500">{{ $message }}</p>
                        @enderror
                        @if ($regionCountryRows !== [])
                            <div class="flex flex-wrap gap-2">
                                @foreach ($regionCountryRows as $row)
                                    <div class="flex items-center gap-2 rounded-md border border-neutral-200 px-2 py-1 text-sm dark:border-white/[0.08]"
                                        wire:key="region-country-{{ $row['name'] }}">
                                        <span>{{ $row['label'] }}</span>
                                        <button type="button" class="button"
                                            wire:click="removeRegionCountry({{ \Illuminate\Support\Js::from($row['name']) }})">{{ __('Remove') }}</button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        <div class="flex flex-wrap gap-2">
                            <x-forms.button type="submit" isHighlighted>{{ __('Save region') }}</x-forms.button>
                            <button type="button" class="button" wire:click="cancelRegionEditor">{{ __('Cancel') }}</button>
                        </div>
                    </form>
                </x-application.settings-section>
            @else
                <x-application.settings-section title="{{ __('Regions') }}" flush>
                    @if ($regions->isEmpty())
                        <x-empty size="sm" title="{{ __('No regions yet.') }}"
                            description="{{ __('Create a region, then attach it to a plan.') }}" icon-name="globe" />
                    @else
                        <div class="overflow-x-auto">
                            <div class="data-table">
                                <div class="data-table-header getodoo-regions-table-grid">
                                    <span>{{ __('Region name') }}</span>
                                    <span>{{ __('Countries') }}</span>
                                    <span></span>
                                </div>
                                @foreach ($regions as $region)
                                    <div class="data-table-row getodoo-regions-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]"
                                        wire:key="region-row-{{ $region->id }}">
                                        <div class="min-w-0 truncate text-[13px] font-medium">{{ $region->name }}</div>
                                        <div class="min-w-0 text-[12px] text-neutral-500 dark:text-fg-dim">
                                            {{ $region->children->pluck('name')->implode(', ') ?: __('No countries') }}
                                        </div>
                                        <div class="flex shrink-0 items-center justify-end gap-0.5">
                                            <button type="button"
                                                class="group/tool flex h-7 max-w-7 items-center overflow-hidden rounded-md text-neutral-400 transition-all duration-200 hover:max-w-32 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                                wire:click="editRegion({{ $region->id }})"
                                                aria-label="{{ __('Edit') }}">
                                                <span class="flex size-7 shrink-0 items-center justify-center">
                                                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                                        <path d="M4.75 16.75v2.5h2.5l9.2-9.2-2.5-2.5-9.2 9.2Z" stroke-width="1.5" stroke-linejoin="round" />
                                                        <path d="m13.2 7.3 2.5 2.5" stroke-width="1.5" stroke-linecap="round" />
                                                    </svg>
                                                </span>
                                                <span class="overflow-hidden pr-2 text-[11px] font-medium whitespace-nowrap opacity-0 transition-opacity duration-200 group-hover/tool:opacity-100">{{ __('Edit') }}</span>
                                            </button>
                                            <button type="button"
                                                class="group/tool flex h-7 max-w-7 items-center overflow-hidden rounded-md text-neutral-400 transition-all duration-200 hover:max-w-32 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                                wire:click="deleteRegion({{ $region->id }})"
                                                wire:confirm="{{ __('Delete this region?') }}"
                                                aria-label="{{ __('Delete') }}">
                                                <span class="flex size-7 shrink-0 items-center justify-center">
                                                    <x-reicon name="trash" class="size-3.5" />
                                                </span>
                                                <span class="overflow-hidden pr-2 text-[11px] font-medium whitespace-nowrap opacity-0 transition-opacity duration-200 group-hover/tool:opacity-100">{{ __('Delete') }}</span>
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </x-application.settings-section>
            @endif
        </div>
    </x-settings.layout>
</div>
