<div>
    <x-slot:title>
        {{ __('Plans') }} | {{ product_name() }}
    </x-slot>

    <x-settings.layout>
        <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">{{ __('Plans') }}</h1>
                    <p class="mt-1 max-w-2xl text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                        {{ __('A plan is the package a new customer buys. Sell it by country, by region, or as Rest of the world at the list price. Create regions under Regions first.') }}
                    </p>
                </div>
                @unless ($showPlanEditor || $membersPlan)
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($showArchived)
                            <x-forms.button type="button" wire:click="showActivePlans">
                                {{ __('Back to plans') }}
                            </x-forms.button>
                        @else
                            <x-forms.button type="button" wire:click="showArchivedPlans">
                                {{ __('Archived') }}
                                @if ($archivedCount > 0)
                                    <span class="ml-1 text-[11px] opacity-70">({{ $archivedCount }})</span>
                                @endif
                            </x-forms.button>
                            <x-forms.button type="button" wire:click="newPlan" isHighlighted>
                                {{ __('New') }}
                            </x-forms.button>
                        @endif
                    </div>
                @endunless
            </div>

            @if ($showPlanEditor)
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
                            label="{{ __('Monthly list price') }}"
                            helper="{{ __('Standard USD price for this plan. Rest of the world always charges this amount. A country or region promo can replace it for that coverage.') }}" />
                        <label class="flex flex-col gap-1 text-sm">
                            <span>{{ __('How this plan is charged') }}</span>
                            <select id="chargeMode" wire:model="chargeMode"
                                class="h-10 rounded-md border border-neutral-300 bg-white px-2 text-sm dark:border-white/15 dark:bg-transparent">
                                <option value="once">{{ __('One-time payment') }}</option>
                                <option value="subscription">{{ __('Monthly subscription') }}</option>
                            </select>
                            <span class="text-[11px] leading-4 text-neutral-500 dark:text-fg-dim">
                                {{ __('A subscription asks n1co to charge this price again every month. A one-time payment is a single charge with Wompi or n1co.') }}
                            </span>
                            @error('chargeMode')
                                <p class="text-sm text-red-500">{{ $message }}</p>
                            @enderror
                        </label>
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
                            <div class="flex flex-col gap-3 rounded-lg border border-neutral-200 p-3 dark:border-white/[0.08]">
                                <p class="text-sm font-medium">{{ __('Server access for the plan owner') }}</p>
                                <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                    {{ __('These flags are written on the buyer’s team membership when they sign up. Without them, the dashboard will not offer adding servers or launching on the instance host.') }}
                                </p>
                                <x-forms.checkbox id="canAddServers" label="{{ __('Can add servers') }}"
                                    helper="{{ __('Allows the organization owner to create and manage their own servers.') }}" />
                                <x-forms.checkbox id="canLaunchOnInstanceServer"
                                    label="{{ __('Can launch on the server where :product is installed', ['product' => product_name()]) }}"
                                    helper="{{ __('Allows launching on the local instance server (id 0), even when that server belongs to the root team.') }}" />
                            </div>
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
                                <p class="text-sm font-medium">{{ __('Where this plan is sold') }}</p>
                                <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                    {{ __('Choose one coverage: Rest of the world (list price everywhere no country/region plan wins), or attach specific countries and/or regions. Rest of the world cannot be combined with countries or regions.') }}
                                </p>
                                <x-forms.checkbox id="planIsRestOfWorld" live
                                    label="{{ __('Rest of the world (list price)') }}"
                                    helper="{{ __('No country is attached. Buyers outside every country and region plan pay the monthly list price above.') }}" />

                                @if (! $planIsRestOfWorld)
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                                        <div class="min-w-0 flex-1">
                                            <x-forms.searchable-listbox id="pendingCountry" portal live
                                                label="{{ __('Add country') }}"
                                                searchPlaceholder="{{ __('Search countries') }}"
                                                emptyText="{{ __('No matching country') }}"
                                                :options="$addCountryChoices" />
                                        </div>
                                        <x-forms.button type="button" wire:click="addPlanCountry">{{ __('Add') }}</x-forms.button>
                                    </div>
                                    @error('pendingCountry')
                                        <p class="text-sm text-red-500">{{ $message }}</p>
                                    @enderror
                                    @error('planCountries')
                                        <p class="text-sm text-red-500">{{ $message }}</p>
                                    @enderror

                                    @if ($planCountryRows !== [])
                                        <div class="flex flex-col gap-2">
                                            @foreach ($planCountryRows as $row)
                                                <div class="flex flex-wrap items-center gap-3 rounded-md border border-neutral-200 px-2 py-2 dark:border-white/[0.08]"
                                                    wire:key="plan-country-{{ $row['key'] }}">
                                                    <span class="min-w-[8rem] flex-1 text-sm font-medium">{{ $row['label'] }}</span>
                                                    <div class="w-36">
                                                        <input type="number" step="0.01" min="0"
                                                            class="input w-full text-[12px]"
                                                            placeholder="{{ __('Promo price') }}"
                                                            wire:model="planCountryPromos.{{ $row['key'] }}">
                                                    </div>
                                                    <button type="button" class="button"
                                                        wire:click="removePlanCountry({{ \Illuminate\Support\Js::from($row['name']) }})">{{ __('Remove') }}</button>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if ($addRegionChoices !== [] || $planRegionRows !== [])
                                        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                                            <div class="min-w-0 flex-1">
                                                <x-forms.listbox id="pendingRegionId" portal live
                                                    label="{{ __('Add region') }}"
                                                    placeholder="{{ __('Select a region') }}"
                                                    :options="$addRegionChoices" />
                                            </div>
                                            <x-forms.button type="button" wire:click="addPlanRegion">{{ __('Add') }}</x-forms.button>
                                        </div>
                                        @error('pendingRegionId')
                                            <p class="text-sm text-red-500">{{ $message }}</p>
                                        @enderror
                                    @else
                                        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                            {{ __('No regions yet. Create them under Configuration → Regions.') }}
                                        </p>
                                    @endif

                                    @if ($planRegionRows !== [])
                                        <div class="flex flex-col gap-2">
                                            @foreach ($planRegionRows as $row)
                                                <div class="flex flex-wrap items-center gap-3 rounded-md border border-neutral-200 px-2 py-2 dark:border-white/[0.08]"
                                                    wire:key="plan-region-{{ $row['id'] }}">
                                                    <span class="min-w-[8rem] flex-1 text-sm font-medium">{{ __('Region') }}: {{ $row['label'] }}</span>
                                                    <div class="w-36">
                                                        <input type="number" step="0.01" min="0"
                                                            class="input w-full text-[12px]"
                                                            placeholder="{{ __('Promo price') }}"
                                                            wire:model="planRegionPromos.{{ $row['id'] }}">
                                                    </div>
                                                    <button type="button" class="button"
                                                        wire:click="removePlanRegion({{ $row['id'] }})">{{ __('Remove') }}</button>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if ($planCountryRows === [] && $planRegionRows === [])
                                        <p class="text-[13px] text-amber-600 dark:text-amber-400">
                                            {{ __('Add countries or regions where this plan is sold.') }}
                                        </p>
                                    @else
                                        <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                            {{ __('Promo price is optional. Empty uses the monthly list price (plus any country extras).') }}
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
                            <button type="button" class="button" wire:click="cancelPlanEditor">{{ __('Cancel') }}</button>
                        </div>
                    </form>
                </x-application.settings-section>
            @elseif ($membersPlan)
                <x-application.settings-section
                    title="{{ __('Members on :plan', ['plan' => $membersPlan->name]) }}">
                    <x-slot:actions>
                        <button type="button" class="button" wire:click="closePlanMembers">{{ __('Back to plans') }}</button>
                    </x-slot:actions>
                    <p class="mb-4 text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                        {{ __('Users on organization teams that bought this plan. The principal is the account that signed up; invitees are admins or members of that team.') }}
                    </p>
                    @if ($planMembers->isEmpty())
                        <x-empty size="sm" title="{{ __('No members yet.') }}"
                            description="{{ __('Nobody has signed up for this plan yet.') }}" icon-name="teams" />
                    @else
                        <div class="data-table">
                            <div class="data-table-header grid grid-cols-[minmax(0,1.1fr)_minmax(0,1.2fr)_minmax(0,1fr)_7rem_max-content] gap-3">
                                <span>{{ __('Name') }}</span>
                                <span>{{ __('Email') }}</span>
                                <span>{{ __('Organization') }}</span>
                                <span>{{ __('Role') }}</span>
                                <span></span>
                            </div>
                            @foreach ($planMembers as $row)
                                <div class="data-table-row grid grid-cols-[minmax(0,1.1fr)_minmax(0,1.2fr)_minmax(0,1fr)_7rem_max-content] gap-3 border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]"
                                    wire:key="plan-member-{{ $row->user->id }}-{{ $row->team_name }}">
                                    <div class="truncate text-[13px] font-medium">{{ $row->user->name }}</div>
                                    <div class="truncate text-[12px] text-neutral-500 dark:text-fg-dim">{{ $row->user->email }}</div>
                                    <div class="truncate text-[12px] text-neutral-500 dark:text-fg-dim">{{ $row->team_name }}</div>
                                    <div>
                                        @if ($row->is_principal)
                                            <span
                                                class="inline-flex rounded-full bg-coollabs/10 px-2 py-0.5 text-[10px] font-medium text-coollabs dark:bg-warning/15 dark:text-warning">
                                                {{ __('Principal') }}
                                            </span>
                                        @else
                                            <span
                                                class="inline-flex rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium capitalize text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                                                {{ $row->role }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="flex justify-end">
                                        <a class="button" href="{{ route('team.admin-view', ['search' => $row->user->email]) }}">
                                            {{ __('Manage') }}
                                        </a>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-application.settings-section>
            @else
                <x-application.settings-section
                    title="{{ $showArchived ? __('Archived plans') : __('Plans') }}" flush>
                    @if ($plans->isEmpty())
                        @if ($showArchived)
                            <x-empty size="sm" title="{{ __('No archived plans.') }}"
                                description="{{ __('Archive a plan to hide it from signup without deleting its organizations.') }}"
                                icon-name="tags" />
                        @else
                            <x-empty size="sm" title="{{ __('No plans yet.') }}"
                                description="{{ __('Create a plan to get the signup link for the website.') }}" icon-name="tags" />
                        @endif
                    @else
                        <div class="data-table">
                            <div class="data-table-header getodoo-plans-table-grid">
                                <span>{{ __('Plan name') }}</span>
                                <span>{{ __('Monthly price') }}</span>
                                <span>{{ __('Scope') }}</span>
                                <span>{{ __('Members') }}</span>
                                <span>{{ __('Signup link') }}</span>
                            </div>
                            @foreach ($plans as $plan)
                                <div class="data-table-row getodoo-plans-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]"
                                    wire:key="getodoo-plan-{{ $plan->id }}">
                                    <div class="min-w-0">
                                        <div class="truncate text-[13px] font-medium">{{ $plan->name }}</div>
                                    </div>
                                    <div class="text-[13px]">
                                        {{ $plan->isFree() ? __('Free') : '$'.number_format((float) $plan->price, 2) }}
                                        <div class="text-[11px] text-neutral-500 dark:text-fg-dim">
                                            {{ $plan->chargesAsSubscription() ? __('Monthly subscription') : __('One-time payment') }}
                                        </div>
                                    </div>
                                    <div class="min-w-0 truncate text-[12px] text-neutral-500 dark:text-fg-dim">
                                        {{ $plan->scopeLabel() }}
                                    </div>
                                    <div class="min-w-0">
                                        <button type="button"
                                            class="text-[13px] font-medium text-coollabs underline-offset-2 hover:underline dark:text-warning"
                                            wire:click="showPlanMembers({{ $plan->id }})"
                                            title="{{ __('Open members on this plan') }}">
                                            {{ (int) ($plan->members_count ?? 0) }}
                                        </button>
                                        <div class="text-[11px] text-neutral-500 dark:text-fg-dim">
                                            {{ trans_choice(':count organization|:count organizations', (int) $plan->teams_count, ['count' => (int) $plan->teams_count]) }}
                                        </div>
                                    </div>
                                    <div class="getodoo-plans-link-cell min-w-0">
                                        <input class="input min-w-0 flex-1 font-mono text-[12px]" readonly value="{{ $plan->publicUrl() }}">
                                        <div class="getodoo-plans-row-actions">
                                            <button type="button"
                                                class="group/tool flex h-7 max-w-7 items-center overflow-hidden rounded-md text-neutral-400 transition-all duration-200 hover:max-w-32 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                                x-on:click.prevent="await window.copyToClipboard({{ \Illuminate\Support\Js::from($plan->publicUrl()) }})"
                                                aria-label="{{ __('Copy') }}">
                                                <span class="flex size-7 shrink-0 items-center justify-center">
                                                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                                        <path d="M8 8.75H6.5A2.25 2.25 0 0 0 4.25 11v6.5a2.25 2.25 0 0 0 2.25 2.25H13a2.25 2.25 0 0 0 2.25-2.25V16" stroke-width="1.5" stroke-linecap="round" />
                                                        <rect x="8.75" y="4.25" width="11" height="11" rx="2.25" stroke-width="1.5" />
                                                    </svg>
                                                </span>
                                                <span class="overflow-hidden pr-2 text-[11px] font-medium whitespace-nowrap opacity-0 transition-opacity duration-200 group-hover/tool:opacity-100">{{ __('Copy') }}</span>
                                            </button>
                                            <button type="button"
                                                class="group/tool flex h-7 max-w-7 items-center overflow-hidden rounded-md text-neutral-400 transition-all duration-200 hover:max-w-32 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                                wire:click="editPlan({{ $plan->id }})"
                                                aria-label="{{ __('Edit') }}">
                                                <span class="flex size-7 shrink-0 items-center justify-center">
                                                    <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                                        <path d="M4.75 16.75v2.5h2.5l9.2-9.2-2.5-2.5-9.2 9.2Z" stroke-width="1.5" stroke-linejoin="round" />
                                                        <path d="m13.2 7.3 2.5 2.5" stroke-width="1.5" stroke-linecap="round" />
                                                    </svg>
                                                </span>
                                                <span class="overflow-hidden pr-2 text-[11px] font-medium whitespace-nowrap opacity-0 transition-opacity duration-200 group-hover/tool:opacity-100">{{ __('Edit') }}</span>
                                            </button>
                                            @if ($showArchived)
                                                <button type="button"
                                                    class="group/tool flex h-7 max-w-7 items-center overflow-hidden rounded-md text-neutral-400 transition-all duration-200 hover:max-w-36 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                                    wire:click="restorePlan({{ $plan->id }})"
                                                    wire:confirm="{{ __('Restore this plan and offer it for signup again?') }}"
                                                    aria-label="{{ __('Restore') }}">
                                                    <span class="flex size-7 shrink-0 items-center justify-center">
                                                        <x-reicon name="refresh" class="size-3.5" />
                                                    </span>
                                                    <span class="overflow-hidden pr-2 text-[11px] font-medium whitespace-nowrap opacity-0 transition-opacity duration-200 group-hover/tool:opacity-100">{{ __('Restore') }}</span>
                                                </button>
                                            @else
                                                <button type="button"
                                                    class="group/tool flex h-7 max-w-7 items-center overflow-hidden rounded-md text-neutral-400 transition-all duration-200 hover:max-w-36 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                                    wire:click="archivePlan({{ $plan->id }})"
                                                    wire:confirm="{{ __('Archive this plan? It will stop appearing for new signups. Existing organizations keep their access.') }}"
                                                    aria-label="{{ __('Archive') }}">
                                                    <span class="flex size-7 shrink-0 items-center justify-center">
                                                        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                                            <path d="M4.75 7.25h14.5v2.5H4.75v-2.5Z" stroke-width="1.5" stroke-linejoin="round" />
                                                            <path d="M6.25 9.75v7.5h11.5v-7.5" stroke-width="1.5" stroke-linejoin="round" />
                                                            <path d="M10 13.25h4" stroke-width="1.5" stroke-linecap="round" />
                                                        </svg>
                                                    </span>
                                                    <span class="overflow-hidden pr-2 text-[11px] font-medium whitespace-nowrap opacity-0 transition-opacity duration-200 group-hover/tool:opacity-100">{{ __('Archive') }}</span>
                                                </button>
                                            @endif
                                            @if ((int) $plan->teams_count === 0)
                                                <button type="button"
                                                    class="group/tool flex h-7 max-w-7 items-center overflow-hidden rounded-md text-neutral-400 transition-all duration-200 hover:max-w-32 hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                                    wire:click="deletePlan({{ $plan->id }})"
                                                    wire:confirm="{{ __('Delete this plan? Pending signup rows for this plan are removed too.') }}"
                                                    aria-label="{{ __('Delete') }}">
                                                    <span class="flex size-7 shrink-0 items-center justify-center">
                                                        <x-reicon name="trash" class="size-3.5" />
                                                    </span>
                                                    <span class="overflow-hidden pr-2 text-[11px] font-medium whitespace-nowrap opacity-0 transition-opacity duration-200 group-hover/tool:opacity-100">{{ __('Delete') }}</span>
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-application.settings-section>
            @endif
        </div>
    </x-settings.layout>
</div>
