<div>
    <x-slot:title>
        {{ __('Plans') }} | {{ product_name() }}
    </x-slot>

    <x-settings.layout>
        <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <div>
                <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">{{ __('Plans') }}</h1>
                <p class="mt-1 max-w-2xl text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                    {{ __('A plan is the package a new customer buys. The link opens registration and the first payment. Regions and countries set the extras added to that price.') }}
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
                        label="{{ __('Monthly price') }}" helper="{{ __('Base USD price before country or region extras. Use 0 for a free plan.') }}" />
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
                            label="{{ __('Includes migration help (GitHub, repository, dump + filestore)') }}" />
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-forms.listbox id="backupFrequency" portal label="{{ __('Automatic backup frequency') }}"
                                :options="$backupFrequencyChoices"
                                helper="{{ __('How often Odoo instances on this plan back up the database and the filestore.') }}" />
                            <x-forms.input id="backupRetentionDays" type="number" min="1" max="365"
                                label="{{ __('Backup retention (days)') }}" />
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

            <x-application.settings-section title="{{ __('Regions and countries') }}">
                <p class="mb-4 text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                    {{ __('Customers pick a country on signup. The charge is the plan price plus region extras plus country extras (percent first, then fixed USD).') }}
                </p>
                <form class="mb-6 flex flex-col gap-4" wire:submit="saveArea">
                    <x-forms.listbox id="areaKind" portal label="{{ __('Type') }}" :options="$areaKindChoices" />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-forms.input id="areaName" required label="{{ __('Name') }}" />
                        <x-forms.input id="areaCode" label="{{ __('Code') }}"
                            helper="{{ __('Lowercase slug. Empty uses the name.') }}" />
                    </div>
                    @if ($areaKind === 'country')
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-forms.input id="areaIso" label="{{ __('ISO code') }}" helper="{{ __('Two letters, e.g. SV.') }}" />
                            @if ($regionChoices !== [])
                                <x-forms.listbox id="areaParentId" portal label="{{ __('Region') }}"
                                    :options="array_merge([['value' => '', 'label' => __('No region')]], $regionChoices)" />
                            @endif
                        </div>
                    @endif
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
                            {{ __('Features for customers in this area. A country can only tighten what its region already allows.') }}
                        </p>
                        <x-forms.checkbox id="areaAllowMultipleProjects"
                            label="{{ __('Allow more than one project / instance') }}" />
                        <x-forms.checkbox id="areaAllowAllServices" live label="{{ __('Allow all catalog services') }}" />
                        @if (! $areaAllowAllServices)
                            <x-forms.textarea id="areaAllowedServices" rows="4"
                                label="{{ __('Allowed services') }}"
                                helper="{{ __('One service key per line, e.g. odoo or redis. Only these appear in Launch.') }}" />
                        @endif
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-forms.button type="submit" isHighlighted>{{ __('Save area') }}</x-forms.button>
                        <button type="button" class="button" wire:click="newArea">{{ __('New area') }}</button>
                    </div>
                </form>

                <div class="grid gap-4 lg:grid-cols-2">
                    <div>
                        <h3 class="mb-2 text-[13px] font-semibold">{{ __('Regions') }}</h3>
                        @if ($regions->isEmpty())
                            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">{{ __('No regions yet.') }}</p>
                        @else
                            <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-white/[0.08]">
                                @foreach ($regions as $region)
                                    <div class="flex items-center justify-between gap-2 border-b border-neutral-200 px-3 py-2 last:border-b-0 dark:border-white/[0.08]"
                                        wire:key="pricing-region-{{ $region->id }}">
                                        <div class="min-w-0">
                                            <div class="truncate text-[13px] font-medium">{{ $region->name }}</div>
                                            <div class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                                +{{ number_format((float) $region->extra_percent, 2) }}%
                                                · +${{ number_format((float) $region->extra_fixed, 2) }}
                                                · {{ $region->allow_multiple_projects ? __('Multi project') : __('Single project') }}
                                                · {{ $region->allow_all_services ? __('All services') : __('Limited services') }}
                                                @unless ($region->is_active)
                                                    · {{ __('Hidden') }}
                                                @endunless
                                            </div>
                                        </div>
                                        <div class="flex shrink-0 gap-1">
                                            <button type="button" class="button" wire:click="editArea({{ $region->id }})">{{ __('Edit') }}</button>
                                            <button type="button" class="button" wire:click="deleteArea({{ $region->id }})"
                                                wire:confirm="{{ __('Delete this region?') }}">{{ __('Delete') }}</button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <div>
                        <h3 class="mb-2 text-[13px] font-semibold">{{ __('Countries') }}</h3>
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
                                                {{ $country->parent?->name ?? __('No region') }}
                                                · +{{ number_format((float) $country->extra_percent, 2) }}%
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
                    </div>
                </div>
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
