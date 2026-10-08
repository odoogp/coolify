<div>
    <x-slot:title>
        {{ __('Plans') }} | {{ product_name() }}
    </x-slot>

    <x-settings.layout>
        <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <div>
                <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">{{ __('Plans') }}</h1>
                <p class="mt-1 max-w-2xl text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                    {{ __('A plan is the package a new customer buys. The link opens registration and the first payment. Countries set price extras and which services that customer may launch. A plan can be limited to specific countries.') }}
                </p>
            </div>

            <x-application.settings-section :title="$planId ? __('Edit plan') : __('New plan')">
                <form class="flex flex-col gap-4" wire:submit="savePlan">
                    <x-forms.input id="name" required label="{{ __('Plan name') }}" />
                    <x-forms.input id="summary" label="{{ __('Short summary') }}" />
                    <div>
                        <label class="mb-1.5 block text-sm font-medium" for="plan-description">{{ __('What is included') }}</label>
                        <textarea id="plan-description" class="input min-h-28 w-full" wire:model="description"></textarea>
                        @error('description')
                            <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                        @enderror
                    </div>
                    <x-forms.input id="price" type="number" step="0.01" min="0" required
                        label="{{ __('Monthly price') }}" helper="{{ __('Base USD price before country extras. Use 0 for a free plan.') }}" />
                    <div class="flex flex-col gap-3">
                        <p class="text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                            {{ __('These limits belong to this admin. Leave a field empty for no limit. A member never receives servers or S3.') }}
                        </p>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <x-forms.input id="maxProjects" type="number" min="0" label="{{ __('Projects') }}" />
                            <x-forms.input id="maxEnvironments" type="number" min="0" label="{{ __('Environments') }}" />
                            <x-forms.input id="maxMembers" type="number" min="0" label="{{ __('Members') }}" />
                            <x-forms.input id="maxProductionBranches" type="number" min="0" label="{{ __('Production branches') }}" />
                            <x-forms.input id="maxStagingBranches" type="number" min="0" label="{{ __('Staging branches') }}" />
                            <x-forms.input id="maxServices" type="number" min="0" label="{{ __('Services') }}" />
                        </div>
                        <x-forms.checkbox id="canAddServers" label="{{ __('Can add servers') }}" />
                        <x-forms.checkbox id="canLaunchOnInstanceServer"
                            label="{{ __('Can launch instances on the server where GPSH is installed') }}" />
                        <x-forms.checkbox id="includesMigration"
                            label="{{ __('Includes migration help (GitHub, repository, dump + filestore)') }}"
                            helper="{{ __('When on, the customer sees Migrate on the Odoo project. The instance owner always sees it. The wizard also accepts a zip of modules when they are not in GitHub.') }}" />
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-forms.listbox id="backupFrequency" portal label="{{ __('Automatic backup frequency') }}"
                                :options="$backupFrequencyChoices"
                                helper="{{ __('How often Odoo instances on this plan back up the database and the filestore.') }}" />
                            <x-forms.input id="backupRetentionDays" type="number" min="1" max="365"
                                label="{{ __('Backup retention (days)') }}" />
                        </div>
                        <div class="flex flex-col gap-3 rounded-lg border border-neutral-200 p-3 dark:border-white/[0.08]">
                            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                {{ __('Where this plan is sold. Worldwide means every configured country. Otherwise pick the countries that may use this plan.') }}
                            </p>
                            <x-forms.checkbox id="planAvailableWorldwide" live
                                label="{{ __('Available in every country') }}" />
                            @if (! $planAvailableWorldwide)
                                @if ($countryCheckChoices === [])
                                    <p class="text-[13px] text-amber-600 dark:text-amber-400">
                                        {{ __('Add countries below first, then select which ones get this plan.') }}
                                    </p>
                                @else
                                    <div class="flex max-h-64 flex-col gap-2 overflow-y-auto">
                                        @foreach ($countryCheckChoices as $country)
                                            <div class="flex flex-wrap items-center gap-3 rounded-md border border-neutral-200 px-2 py-2 dark:border-white/[0.08]"
                                                wire:key="plan-country-{{ $country['value'] }}">
                                                <label class="flex min-w-[10rem] flex-1 items-center gap-2 text-sm">
                                                    <input type="checkbox" class="rounded" value="{{ $country['value'] }}"
                                                        wire:model.live="planCountryIds">
                                                    <span>{{ $country['label'] }}</span>
                                                </label>
                                                @if (in_array($country['value'], $planCountryIds, true))
                                                    <div class="w-36">
                                                        <input type="number" step="0.01" min="0"
                                                            class="input w-full text-[12px]"
                                                            placeholder="{{ __('Promo price') }}"
                                                            wire:model="planCountryPromos.{{ $country['value'] }}">
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                        {{ __('Optional promo price (USD) is exclusive for that country and replaces the normal quote on signup.') }}
                                    </p>
                                @endif
                                @error('planCountryIds')
                                    <p class="text-sm text-red-500">{{ $message }}</p>
                                @enderror
                            @endif
                        </div>
                        <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                            {{ __('The GitHub account is chosen later, on this client\'s team.') }}
                        </p>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" class="rounded" wire:model="active">
                        {{ __('Active') }}
                    </label>
                    <div class="flex flex-wrap gap-2">
                        <x-forms.button type="submit" isHighlighted>{{ __('Save plan') }}</x-forms.button>
                        @if ($planId)
                            <button type="button" class="button" wire:click="newPlan">{{ __('New plan') }}</button>
                        @endif
                    </div>
                </form>
            </x-application.settings-section>

            <x-application.settings-section title="{{ __('Countries') }}">
                <p class="mb-4 text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                    {{ __('Pick a standard country, set price extras, and which catalog services customers there may launch. Signup asks for a country when at least one is active.') }}
                </p>
                <form class="mb-6 flex flex-col gap-4" wire:submit="saveArea">
                    <x-forms.searchable-listbox id="areaIso" portal required label="{{ __('Country') }}"
                        searchPlaceholder="{{ __('Search countries') }}"
                        emptyText="{{ __('No matching country') }}"
                        :options="$standardCountryChoices"
                        helper="{{ __('Standard ISO list. Each country can be configured once.') }}" />
                    <div class="grid gap-3 sm:grid-cols-3">
                        <x-forms.input id="areaExtraPercent" type="number" step="0.01" min="0"
                            label="{{ __('Extra %') }}" />
                        <x-forms.input id="areaExtraFixed" type="number" step="0.01" min="0"
                            label="{{ __('Extra fixed (USD)') }}" />
                        <x-forms.input id="areaSort" type="number" min="0" label="{{ __('Sort order') }}" />
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" class="rounded" wire:model="areaActive">
                        {{ __('Visible for signup') }}
                    </label>
                    <div class="flex flex-col gap-3 rounded-lg border border-neutral-200 p-3 dark:border-white/[0.08]">
                        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                            {{ __('Features for customers in this country.') }}
                        </p>
                        <x-forms.checkbox id="areaAllowMultipleProjects"
                            label="{{ __('Allow more than one project / instance') }}" />
                        <x-forms.checkbox id="areaAllowAllServices" live label="{{ __('Allow all catalog services') }}" />
                        @if (! $areaAllowAllServices)
                            <div>
                                <p class="mb-2 text-sm font-medium">{{ __('Allowed services') }}</p>
                                <p class="mb-2 text-[12px] text-neutral-500 dark:text-fg-dim">
                                    {{ __('Services the platform already knows. Only checked ones appear in Launch for this country.') }}
                                </p>
                                <div class="grid max-h-56 gap-2 overflow-y-auto sm:grid-cols-2">
                                    @foreach ($catalogServices as $service)
                                        <label class="flex items-start gap-2 text-sm" wire:key="area-service-{{ $service['value'] }}">
                                            <input type="checkbox" class="mt-0.5 rounded" value="{{ $service['value'] }}"
                                                wire:model="areaAllowedServiceKeys">
                                            <span>
                                                <span class="font-medium">{{ $service['label'] }}</span>
                                                <span class="block font-mono text-[11px] text-neutral-500 dark:text-fg-faint">{{ $service['value'] }}</span>
                                            </span>
                                        </label>
                                    @endforeach
                                </div>
                                @error('areaAllowedServiceKeys')
                                    <p class="mt-1 text-sm text-red-500">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-forms.button type="submit" isHighlighted>{{ __('Save country') }}</x-forms.button>
                        <button type="button" class="button" wire:click="newArea">{{ __('New country') }}</button>
                    </div>
                </form>

                @if ($countries->isEmpty())
                    <p class="text-[13px] text-neutral-500 dark:text-fg-dim">{{ __('No countries yet. Until you add one, signup uses the base plan price.') }}</p>
                @else
                    <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-white/[0.08]">
                        @foreach ($countries as $country)
                            <div class="flex items-center justify-between gap-2 border-b border-neutral-200 px-3 py-2 last:border-b-0 dark:border-white/[0.08]"
                                wire:key="pricing-country-{{ $country->id }}">
                                <div class="min-w-0">
                                    <div class="truncate text-[13px] font-medium">{{ $country->name }}</div>
                                    <div class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                        +{{ number_format((float) $country->extra_percent, 2) }}%
                                        · +${{ number_format((float) $country->extra_fixed, 2) }}
                                        · {{ $country->allow_multiple_projects ? __('Multi project') : __('Single project') }}
                                        · {{ $country->allow_all_services ? __('All services') : __('Limited services') }}
                                        @unless ($country->is_active)
                                            · {{ __('Hidden') }}
                                        @endunless
                                    </div>
                                </div>
                                <div class="flex shrink-0 gap-1">
                                    <button type="button" class="button" wire:click="editArea({{ $country->id }})">{{ __('Edit') }}</button>
                                    <button type="button" class="button" wire:click="deleteArea({{ $country->id }})"
                                        wire:confirm="{{ __('Delete this country?') }}">{{ __('Delete') }}</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-application.settings-section>

            <x-application.settings-section title="{{ __('Signup link') }}" flush>
                @if ($plans->isEmpty())
                    <x-empty size="sm" title="{{ __('No plans yet.') }}"
                        description="{{ __('Save a plan to get the link for the website.') }}" icon-name="tags" />
                @else
                    <div class="overflow-x-auto">
                        <div class="data-table">
                            <div class="data-table-header getodoo-plans-table-grid">
                                <span>{{ __('Plan name') }}</span>
                                <span>{{ __('Monthly price') }}</span>
                                <span>{{ __('Countries') }}</span>
                                <span>{{ __('Signup link') }}</span>
                                <span></span>
                            </div>
                            @foreach ($plans as $plan)
                                <div class="data-table-row getodoo-plans-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]"
                                    wire:key="getodoo-plan-{{ $plan->id }}">
                                    <div class="min-w-0">
                                        <div class="truncate text-[13px] font-medium">{{ $plan->name }}</div>
                                        @if (! $plan->is_active)
                                            <div class="text-[11px] text-neutral-500">{{ __('Inactive') }}</div>
                                        @endif
                                    </div>
                                    <div class="text-[13px]">
                                        {{ $plan->isFree() ? __('Free') : '$'.number_format((float) $plan->price, 2) }}
                                    </div>
                                    <div class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                        @if ($plan->pricingAreas->isEmpty())
                                            {{ __('Worldwide') }}
                                        @else
                                            {{ $plan->pricingAreas->map(function ($area) {
                                                $label = $area->name;
                                                if ($area->pivot?->promo_price !== null) {
                                                    $label .= ' $'.number_format((float) $area->pivot->promo_price, 2);
                                                }

                                                return $label;
                                            })->implode(', ') }}
                                        @endif
                                    </div>
                                    <div class="min-w-0">
                                        <input class="input w-full font-mono text-[12px]" readonly value="{{ $plan->publicUrl() }}">
                                    </div>
                                    <div class="flex justify-end gap-2">
                                        <button type="button" class="button" wire:click="editPlan({{ $plan->id }})">{{ __('Edit') }}</button>
                                        @if ($plan->signups_count === 0)
                                            <button type="button" class="button" wire:click="deletePlan({{ $plan->id }})"
                                                wire:confirm="{{ __('Delete this plan?') }}">{{ __('Delete') }}</button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    </x-settings.layout>
</div>
