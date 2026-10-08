<div>
    <x-slot:title>
        {{ __('Plans') }} | {{ product_name() }}
    </x-slot>

    <x-settings.layout>
        <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <div>
                <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">{{ __('Plans') }}</h1>
                <p class="mt-1 max-w-2xl text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                    {{ __('A plan is the package a new customer buys. Countries belong to the plan: pick where it is sold and optional promo prices for each country.') }}
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
                        label="{{ __('Monthly price') }}" helper="{{ __('Base USD price. A country promo on this plan can replace it on signup.') }}" />
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
                            helper="{{ __('When on, signup shows migration help as included. Anyone who can update an Odoo project can open Migrate.') }}" />
                        <x-forms.checkbox id="includesBackups" live
                            label="{{ __('Includes automatic backups') }}"
                            helper="{{ __('When on, Odoo on this plan schedules database + filestore backups. Leave off for no automatic backups.') }}" />
                        @if ($includesBackups)
                            <div class="grid gap-3 sm:grid-cols-2">
                                <x-forms.listbox id="backupFrequency" portal label="{{ __('Automatic backup frequency') }}"
                                    :options="$backupFrequencyChoices"
                                    helper="{{ __('How often Odoo instances on this plan back up the database and the filestore.') }}" />
                                <x-forms.input id="backupRetentionDays" type="number" min="1" max="365"
                                    label="{{ __('Backup retention (days)') }}" />
                            </div>
                        @endif

                        <div class="flex flex-col gap-3 rounded-lg border border-neutral-200 p-3 dark:border-white/[0.08]">
                            <p class="text-sm font-medium">{{ __('Countries for this plan') }}</p>
                            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                {{ __('Define countries here on the plan. Worldwide means any country. Otherwise only the countries you add can buy this plan.') }}
                            </p>
                            <x-forms.checkbox id="planAvailableWorldwide" live
                                label="{{ __('Available worldwide (no country limit)') }}" />

                            @if (! $planAvailableWorldwide)
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                                    <div class="min-w-0 flex-1">
                                        <x-forms.searchable-listbox id="pendingCountryIso" portal live
                                            label="{{ __('Add country') }}"
                                            searchPlaceholder="{{ __('Search countries') }}"
                                            emptyText="{{ __('No matching country') }}"
                                            :options="$addCountryChoices" />
                                    </div>
                                    <x-forms.button type="button" wire:click="addPlanCountry">{{ __('Add') }}</x-forms.button>
                                </div>
                                @error('pendingCountryIso')
                                    <p class="text-sm text-red-500">{{ $message }}</p>
                                @enderror
                                @error('planCountryIsos')
                                    <p class="text-sm text-red-500">{{ $message }}</p>
                                @enderror

                                @if ($planCountryRows === [])
                                    <p class="text-[13px] text-amber-600 dark:text-amber-400">
                                        {{ __('Add the countries where this plan is sold.') }}
                                    </p>
                                @else
                                    <div class="flex flex-col gap-2">
                                        @foreach ($planCountryRows as $row)
                                            <div class="flex flex-wrap items-center gap-3 rounded-md border border-neutral-200 px-2 py-2 dark:border-white/[0.08]"
                                                wire:key="plan-iso-{{ $row['iso'] }}">
                                                <span class="min-w-[8rem] flex-1 text-sm font-medium">{{ $row['label'] }}</span>
                                                <div class="w-36">
                                                    <input type="number" step="0.01" min="0"
                                                        class="input w-full text-[12px]"
                                                        placeholder="{{ __('Promo price') }}"
                                                        wire:model="planCountryPromos.{{ $row['iso'] }}">
                                                </div>
                                                <button type="button" class="button"
                                                    wire:click="removePlanCountry('{{ $row['iso'] }}')">{{ __('Remove') }}</button>
                                            </div>
                                        @endforeach
                                    </div>
                                    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                        {{ __('Promo price is optional and exclusive to that country on this plan. Empty uses the plan monthly price.') }}
                                    </p>
                                @endif
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
