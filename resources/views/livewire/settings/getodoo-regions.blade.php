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
                                        <div class="flex justify-end gap-2">
                                            <button type="button" class="button"
                                                wire:click="editRegion({{ $region->id }})">{{ __('Edit') }}</button>
                                            <button type="button" class="button" wire:click="deleteRegion({{ $region->id }})"
                                                wire:confirm="{{ __('Delete this region?') }}">{{ __('Delete') }}</button>
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
