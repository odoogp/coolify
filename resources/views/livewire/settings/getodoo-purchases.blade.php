<div>
    <x-slot:title>
        {{ __('Billing | Coolify') }}
    </x-slot>

    <x-settings.layout>
        <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">{{ __('Billing') }}</h1>
                    <p class="mt-1 max-w-2xl text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                        {{ __('Clients, purchases, and invoices in one place. Only the instance owner sees this.') }}
                    </p>
                </div>
                <div class="inline-flex w-fit shrink-0 rounded-[10px] bg-neutral-100 p-1 dark:bg-white/[0.05]">
                    <button type="button" wire:click="setTab('clients')"
                        class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-medium transition-colors {{ $activeTab === 'clients' ? 'bg-white text-neutral-950 shadow-sm dark:bg-warning/15 dark:text-warning' : 'text-neutral-500 hover:text-neutral-900 dark:text-fg-dim dark:hover:text-fg' }}">
                        {{ __('Clients') }}
                    </button>
                    <button type="button" wire:click="setTab('purchases')"
                        class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-medium transition-colors {{ $activeTab === 'purchases' ? 'bg-white text-neutral-950 shadow-sm dark:bg-warning/15 dark:text-warning' : 'text-neutral-500 hover:text-neutral-900 dark:text-fg-dim dark:hover:text-fg' }}">
                        {{ __('Purchases') }}
                    </button>
                    <button type="button" wire:click="setTab('invoices')"
                        class="inline-flex items-center rounded-md px-3 py-1.5 text-xs font-medium transition-colors {{ $activeTab === 'invoices' ? 'bg-white text-neutral-950 shadow-sm dark:bg-warning/15 dark:text-warning' : 'text-neutral-500 hover:text-neutral-900 dark:text-fg-dim dark:hover:text-fg' }}">
                        {{ __('Invoices') }}
                        @if ($metrics['pending_invoices'] > 0)
                            <span class="ml-1.5 inline-flex min-w-5 items-center justify-center rounded-full bg-coollabs/15 px-1.5 text-[10px] font-semibold text-coollabs dark:bg-warning/20 dark:text-warning">
                                {{ $metrics['pending_invoices'] }}
                            </span>
                        @endif
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-[10px] bg-white px-3 py-3 ring-1 ring-neutral-200/80 dark:bg-transparent dark:ring-white/[0.08]">
                    <div class="text-[11px] font-medium uppercase tracking-wide text-neutral-500 dark:text-fg-faint">{{ __('Organizations') }}</div>
                    <div class="mt-1 text-[20px] font-semibold tracking-tight">{{ $metrics['organizations'] }}</div>
                    <div class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-dim">
                        {{ __(':count recurring', ['count' => $metrics['recurring_orgs']]) }}
                    </div>
                </div>
                <div class="rounded-[10px] bg-white px-3 py-3 ring-1 ring-neutral-200/80 dark:bg-transparent dark:ring-white/[0.08]">
                    <div class="text-[11px] font-medium uppercase tracking-wide text-neutral-500 dark:text-fg-faint">{{ __('Client monthly') }}</div>
                    <div class="mt-1 text-[20px] font-semibold tracking-tight">${{ number_format($metrics['monthly_client_usd'], 2) }}</div>
                    <div class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-dim">{{ __('Plans + GetOdoo servers') }}</div>
                </div>
                <div class="rounded-[10px] bg-white px-3 py-3 ring-1 ring-neutral-200/80 dark:bg-transparent dark:ring-white/[0.08]">
                    <div class="text-[11px] font-medium uppercase tracking-wide text-neutral-500 dark:text-fg-faint">{{ __('Your server cost') }}</div>
                    <div class="mt-1 text-[20px] font-semibold tracking-tight">${{ number_format($metrics['owner_cost_usd'], 2) }}</div>
                    <div class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-dim">
                        {{ __(':sold sold · :connected connected', ['sold' => $metrics['sold_servers'], 'connected' => $metrics['connected_servers']]) }}
                    </div>
                </div>
                <div class="rounded-[10px] bg-white px-3 py-3 ring-1 ring-neutral-200/80 dark:bg-transparent dark:ring-white/[0.08]">
                    <div class="text-[11px] font-medium uppercase tracking-wide text-neutral-500 dark:text-fg-faint">{{ __('Pending invoices') }}</div>
                    <div class="mt-1 text-[20px] font-semibold tracking-tight">{{ $metrics['pending_invoices'] }}</div>
                    <div class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-dim">
                        {{ __(':count on instance host', ['count' => $metrics['instance_clients']]) }}
                    </div>
                </div>
            </div>

            @if ($activeTab === 'clients')
                <x-application.settings-section title="{{ __('Clients') }}" flush>
                    <p class="border-b border-neutral-200 px-4 py-3 text-[13px] leading-5 text-neutral-500 dark:border-white/[0.08] dark:text-fg-dim">
                        {{ __('Organizations on a plan: principal, preference, and invoices the client pays in Billing.') }}
                    </p>
                    @if ($planTeams->isEmpty())
                        <x-empty size="sm" title="{{ __('No organization plans yet.') }}"
                            description="{{ __('When a client buys a plan, the organization appears here.') }}" icon-name="teams" />
                    @else
                        <div class="overflow-x-auto">
                            <div class="data-table">
                                <div class="data-table-header getodoo-owner-billing-orgs-grid">
                                    <span>{{ __('Organization') }}</span>
                                    <span>{{ __('Principal') }}</span>
                                    <span>{{ __('Invoice date') }}</span>
                                    <span>{{ __('Plan') }}</span>
                                    <span>{{ __('Monthly') }}</span>
                                    <span>{{ __('Preference') }}</span>
                                    <span>{{ __('Next billing') }}</span>
                                </div>
                                @foreach ($planTeams as $planTeam)
                                    @php
                                        $principal = $principals[$planTeam->id] ?? null;
                                        $isRecurring = $planTeam->prefersPlanRecurringBilling();
                                    @endphp
                                    <div class="data-table-row getodoo-owner-billing-orgs-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]"
                                        wire:key="getodoo-plan-team-{{ $planTeam->id }}">
                                        <div class="min-w-0">
                                            <div class="truncate text-[13px] font-medium">{{ $planTeam->name }}</div>
                                            @if ($planTeam->members->count() > 1)
                                                <div class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                                    {{ __(':count members', ['count' => $planTeam->members->count()]) }}
                                                </div>
                                            @endif
                                        </div>
                                        <div class="min-w-0">
                                            @if ($principal)
                                                <div class="truncate text-[13px]">{{ $principal->name }}</div>
                                                <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $principal->email }}</div>
                                            @else
                                                <span class="text-neutral-400">-</span>
                                            @endif
                                        </div>
                                        <div class="min-w-0 text-[13px]">
                                            @if ($planTeam->getodoo_plan_paid_until)
                                                {{ $planTeam->getodoo_plan_paid_until->isoFormat('D MMM YYYY') }}
                                                @if ($planTeam->getodoo_plan_paid_until->copy()->endOfDay()->isPast())
                                                    <div class="mt-1"><x-status-badge status="{{ __('Overdue') }}" type="error" /></div>
                                                @endif
                                            @else
                                                <span class="text-neutral-500 dark:text-fg-dim">{{ __('Not billed yet') }}</span>
                                            @endif
                                        </div>
                                        <div class="truncate text-[13px]">{{ $planTeam->getodooPlan?->name }}</div>
                                        <div class="text-[13px]">
                                            ${{ number_format($planTeam->getodooPlanMonthlyAmount(), 2) }}
                                            @if ((float) $planTeam->getodoo_plan_monthly_extra > 0)
                                                <div class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                                    {{ __('includes :amount extra', ['amount' => '$'.number_format((float) $planTeam->getodoo_plan_monthly_extra, 2)]) }}
                                                </div>
                                            @endif
                                        </div>
                                        <div class="min-w-0 overflow-hidden text-[13px]">
                                            @if ($isRecurring)
                                                <button type="button"
                                                    class="button w-[8.5rem] min-w-[8.5rem] max-w-[8.5rem] justify-center overflow-hidden"
                                                    wire:click="disablePlanRecurring({{ $planTeam->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="disablePlanRecurring,enablePlanRecurring,openPlanInvoiceForm"
                                                    title="{{ __('Cancel recurring') }}">
                                                    <span class="truncate">{{ __('Cancel recurring') }}</span>
                                                </button>
                                            @else
                                                <button type="button"
                                                    class="button w-[8.5rem] min-w-[8.5rem] max-w-[8.5rem] justify-center overflow-hidden"
                                                    wire:click="enablePlanRecurring({{ $planTeam->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="enablePlanRecurring,disablePlanRecurring,openPlanInvoiceForm"
                                                    title="{{ __('Make recurring') }}">
                                                    <span class="truncate">{{ __('Make recurring') }}</span>
                                                </button>
                                            @endif
                                        </div>
                                        <div class="min-w-0">
                                            <button type="button" class="button button-highlighted"
                                                wire:click="openPlanInvoiceForm({{ $planTeam->id }})"
                                                wire:loading.attr="disabled" wire:target="openPlanInvoiceForm">
                                                {{ __('Create invoice') }}
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </x-application.settings-section>
            @endif

            @if ($activeTab === 'purchases')
                <x-application.settings-section title="{{ __('Purchases') }}"
                    helper="{{ __('Servers you resell through GetOdoo, the instance host, and servers clients connected themselves.') }}">
                    <div class="flex flex-col gap-6 p-4">
                        @if ($instanceHost && $instanceTeams->isNotEmpty())
                            <div>
                                <div class="mb-2 flex items-baseline justify-between gap-3">
                                    <h3 class="text-[13px] font-semibold">{{ __('Instance host') }}</h3>
                                    <span class="text-[11px] text-neutral-500 dark:text-fg-dim">{{ $instanceHost->name }}</span>
                                </div>
                                <p class="mb-3 text-[12px] text-neutral-500 dark:text-fg-dim">
                                    {{ __('Shared host for client projects. Cost is not billed through GetOdoo server offers.') }}
                                </p>
                                <div class="overflow-x-auto rounded-[10px] ring-1 ring-neutral-200/80 dark:ring-white/[0.08]">
                                    <div class="data-table">
                                        <div class="data-table-header grid grid-cols-[minmax(0,1.2fr)_minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1.4fr)] gap-3">
                                            <span>{{ __('Organization') }}</span>
                                            <span>{{ __('Principal') }}</span>
                                            <span>{{ __('Plan') }}</span>
                                            <span>{{ __('Members') }}</span>
                                        </div>
                                        @foreach ($instanceTeams as $instanceTeam)
                                            @php $instancePrincipal = $instancePrincipals[$instanceTeam->id] ?? null; @endphp
                                            <div class="data-table-row grid grid-cols-[minmax(0,1.2fr)_minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1.4fr)] gap-3 border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]"
                                                wire:key="instance-team-{{ $instanceTeam->id }}">
                                                <div class="truncate text-[13px] font-medium">{{ $instanceTeam->name }}</div>
                                                <div class="min-w-0">
                                                    @if ($instancePrincipal)
                                                        <div class="truncate text-[13px]">{{ $instancePrincipal->name }}</div>
                                                        <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $instancePrincipal->email }}</div>
                                                    @else
                                                        <span class="text-neutral-400">-</span>
                                                    @endif
                                                </div>
                                                <div class="truncate text-[13px]">{{ $instanceTeam->getodooPlan?->name ?: '-' }}</div>
                                                <div class="truncate text-[12px] text-neutral-500 dark:text-fg-dim">
                                                    {{ $instanceTeam->members->pluck('name')->filter()->implode(', ') ?: '-' }}
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif

                        <div>
                            <h3 class="mb-2 text-[13px] font-semibold">{{ __('GetOdoo servers') }}</h3>
                            <p class="mb-3 text-[12px] text-neutral-500 dark:text-fg-dim">
                                {{ __('Sold through GetOdoo. Your cost is the provider estimate; client pays is what you bill them.') }}
                            </p>
                            @if ($soldServerRows->isEmpty())
                                <x-empty size="sm" title="{{ __('No GetOdoo servers yet.') }}"
                                    description="{{ __('When a client buys a server from you, it shows here with cost and occupancy.') }}"
                                    icon-name="servers" />
                            @else
                                <div class="overflow-x-auto rounded-[10px] ring-1 ring-neutral-200/80 dark:ring-white/[0.08]">
                                    <div class="data-table">
                                        <div class="data-table-header getodoo-owner-billing-servers-grid">
                                            <span>{{ __('Server') }}</span>
                                            <span>{{ __('Your cost') }}</span>
                                            <span>{{ __('Client pays') }}</span>
                                            <span>{{ __('Clients') }}</span>
                                            <span>{{ __('Preference') }}</span>
                                            <span>{{ __('Next billing') }}</span>
                                            <span></span>
                                        </div>
                                        @foreach ($soldServerRows as $row)
                                            @php
                                                $server = $row['server'];
                                                $isRecurring = $server->prefersRecurringBilling();
                                            @endphp
                                            <div class="data-table-row getodoo-owner-billing-servers-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]"
                                                wire:key="getodoo-sold-{{ $server->id }}">
                                                <div class="min-w-0">
                                                    <div class="truncate text-[13px] font-medium">{{ $server->name }}</div>
                                                    <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                                        {{ $server->getodooOffer?->description ?: $server->getodooOffer?->name }}
                                                    </div>
                                                    <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $server->team?->name }}</div>
                                                </div>
                                                <div class="text-[13px]">
                                                    @if ($row['costUsd'] !== null)
                                                        ${{ number_format((float) $row['costUsd'], 2) }}
                                                        <div class="text-[11px] text-neutral-500 dark:text-fg-faint">USD / {{ __('mo') }}</div>
                                                    @else
                                                        <span class="text-neutral-400">-</span>
                                                    @endif
                                                </div>
                                                <div>
                                                    <input id="getodoo-price-{{ $server->id }}" type="number" step="0.01" min="0.01"
                                                        class="input w-24" wire:model="prices.{{ $server->id }}">
                                                    @error('prices.'.$server->id)
                                                        <div class="mt-1 text-xs text-red-500">{{ $message }}</div>
                                                    @enderror
                                                </div>
                                                <div class="min-w-0 text-[12px]">
                                                    @if ($row['principal'])
                                                        <div class="truncate font-medium text-[13px]">{{ $row['principal']->name }}</div>
                                                        <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $row['principal']->email }}</div>
                                                    @endif
                                                    @if ($row['members']->isNotEmpty())
                                                        <div class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                                            {{ $row['members']->map(fn ($m) => $m->name ?: $m->email)->implode(', ') }}
                                                        </div>
                                                    @elseif (! $row['principal'])
                                                        <span class="text-neutral-400">-</span>
                                                    @endif
                                                </div>
                                                <div class="text-[13px]">
                                                    @if ($isRecurring)
                                                        <x-status-badge status="{{ __('Recurring') }}" type="success" />
                                                    @else
                                                        <x-status-badge status="{{ __('One-time') }}" type="neutral" />
                                                    @endif
                                                </div>
                                                <div class="text-[13px]">
                                                    @if ($server->getodoo_paid_until)
                                                        {{ $server->getodoo_paid_until->isoFormat('D MMM YYYY') }}
                                                        @if ($server->getodoo_paid_until->copy()->endOfDay()->isPast())
                                                            <div class="mt-1"><x-status-badge status="{{ __('Overdue') }}" type="error" /></div>
                                                        @endif
                                                    @else
                                                        <span class="text-neutral-500 dark:text-fg-dim">{{ __('Not billed yet') }}</span>
                                                    @endif
                                                </div>
                                                <div class="flex flex-wrap justify-end gap-2">
                                                    <button type="button" class="button" wire:click="savePrice({{ $server->id }})"
                                                        wire:loading.attr="disabled" wire:target="savePrice,openServerInvoiceForm">
                                                        {{ __('Save price') }}
                                                    </button>
                                                    @if ($isRecurring)
                                                        <button type="button" class="button"
                                                            wire:click="disableServerRecurring({{ $server->id }})"
                                                            wire:loading.attr="disabled"
                                                            wire:target="disableServerRecurring,enableServerRecurring">
                                                            {{ __('Cancel recurring') }}
                                                        </button>
                                                    @else
                                                        <button type="button" class="button"
                                                            wire:click="enableServerRecurring({{ $server->id }})"
                                                            wire:loading.attr="disabled"
                                                            wire:target="enableServerRecurring,disableServerRecurring">
                                                            {{ __('Make recurring') }}
                                                        </button>
                                                    @endif
                                                    <button type="button" class="button button-highlighted"
                                                        wire:click="openServerInvoiceForm({{ $server->id }})"
                                                        wire:loading.attr="disabled" wire:target="openServerInvoiceForm,savePrice">
                                                        {{ __('Create invoice') }}
                                                    </button>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div>
                            <h3 class="mb-2 text-[13px] font-semibold">{{ __('Client-connected servers') }}</h3>
                            <p class="mb-3 text-[12px] text-neutral-500 dark:text-fg-dim">
                                {{ __('Servers a client added themselves (SSH). Not billed as GetOdoo resale; shown for occupancy and control.') }}
                            </p>
                            @if ($connectedServerRows->isEmpty())
                                <x-empty size="sm" title="{{ __('No client-connected servers yet.') }}"
                                    description="{{ __('When a client connects their own server, it appears here with the team that owns it.') }}"
                                    icon-name="servers" />
                            @else
                                <div class="overflow-x-auto rounded-[10px] ring-1 ring-neutral-200/80 dark:ring-white/[0.08]">
                                    <div class="data-table">
                                        <div class="data-table-header grid grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,1.4fr)_minmax(0,0.8fr)] gap-3">
                                            <span>{{ __('Server') }}</span>
                                            <span>{{ __('Team') }}</span>
                                            <span>{{ __('Principal') }}</span>
                                            <span>{{ __('Members') }}</span>
                                            <span>{{ __('Type') }}</span>
                                        </div>
                                        @foreach ($connectedServerRows as $row)
                                            @php $server = $row['server']; @endphp
                                            <div class="data-table-row grid grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,1.4fr)_minmax(0,0.8fr)] gap-3 border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]"
                                                wire:key="getodoo-connected-{{ $server->id }}">
                                                <div class="min-w-0">
                                                    <div class="truncate text-[13px] font-medium">{{ $server->name }}</div>
                                                    <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $server->ip }}</div>
                                                </div>
                                                <div class="truncate text-[13px]">{{ $server->team?->name ?: '-' }}</div>
                                                <div class="min-w-0">
                                                    @if ($row['principal'])
                                                        <div class="truncate text-[13px]">{{ $row['principal']->name }}</div>
                                                        <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $row['principal']->email }}</div>
                                                    @else
                                                        <span class="text-neutral-400">-</span>
                                                    @endif
                                                </div>
                                                <div class="truncate text-[12px] text-neutral-500 dark:text-fg-dim">
                                                    {{ $row['members']->map(fn ($m) => $m->name ?: $m->email)->implode(', ') ?: '-' }}
                                                </div>
                                                <div>
                                                    <x-status-badge status="{{ __('Connected') }}" type="neutral" />
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </x-application.settings-section>
            @endif

            @if ($activeTab === 'invoices')
                @if ($showInvoiceForm)
                    <x-application.settings-section title="{{ __('Create invoice') }}" wire:key="getodoo-invoice-form">
                        <form wire:submit="submitInvoice" class="grid max-w-xl gap-4">
                            <div class="rounded-[10px] bg-neutral-50 px-3 py-2 text-sm ring-1 ring-neutral-200/80 dark:bg-white/[0.03] dark:ring-white/[0.08]">
                                @if ($invoiceTarget === 'server' && $invoiceSubject)
                                    <div class="font-medium">{{ $invoiceSubject->name }}</div>
                                    <div class="text-neutral-500 dark:text-fg-dim">{{ $invoiceSubject->team?->name }}</div>
                                @elseif ($invoiceTarget === 'plan' && $invoiceSubject)
                                    <div class="font-medium">{{ $invoiceSubject->getodooPlan?->name }}</div>
                                    <div class="text-neutral-500 dark:text-fg-dim">{{ $invoiceSubject->name }}</div>
                                @endif
                                @if ($currentMonthly !== null)
                                    <div class="mt-1 text-[12px] text-neutral-500 dark:text-fg-faint">
                                        {{ __('Current monthly total') }}: ${{ number_format((float) $currentMonthly, 2) }}
                                    </div>
                                @endif
                            </div>

                            <label class="flex flex-col gap-1 text-sm">
                                <span>{{ __('Invoice type') }}</span>
                                <select id="invoiceKind" wire:model.live="invoiceKind"
                                    class="h-10 rounded-md border border-neutral-300 bg-white px-2 text-sm dark:border-white/15 dark:bg-transparent">
                                    <option value="once">{{ __('One-time payment') }}</option>
                                    <option value="recurring">{{ __('Add to monthly charge (recurring)') }}</option>
                                </select>
                                <span class="text-[11px] leading-4 text-neutral-500 dark:text-fg-dim">
                                    @if ($invoiceKind === 'recurring')
                                        {{ __('This amount is added to the client’s monthly total from now on. You can also charge it today as a separate invoice.') }}
                                    @else
                                        {{ __('A single invoice for this amount. The monthly subscription total does not change.') }}
                                    @endif
                                </span>
                            </label>

                            <label class="flex flex-col gap-1 text-sm">
                                <span>{{ __('Amount') }} (USD)</span>
                                <input id="invoiceAmount" type="number" step="0.01" min="0.01" required
                                    wire:model="invoiceAmount"
                                    class="h-10 rounded-md border border-neutral-300 bg-white px-2 text-sm dark:border-white/15 dark:bg-transparent">
                                @error('invoiceAmount')
                                    <span class="text-sm text-red-500">{{ $message }}</span>
                                @enderror
                                @if ($invoiceKind === 'recurring' && $currentMonthly !== null && is_numeric($invoiceAmount))
                                    <span class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                        {{ __('New monthly total') }}:
                                        ${{ number_format((float) $currentMonthly + (float) $invoiceAmount, 2) }}
                                    </span>
                                @endif
                            </label>

                            <label class="flex flex-col gap-1 text-sm">
                                <span>{{ __('Description') }}</span>
                                <input id="invoiceDescription" type="text" maxlength="255" wire:model="invoiceDescription"
                                    @if ($invoiceKind === 'once' || $invoiceChargeNow) required @endif
                                    placeholder="{{ __('Shown on the client invoice') }}"
                                    class="h-10 rounded-md border border-neutral-300 bg-white px-2 text-sm dark:border-white/15 dark:bg-transparent">
                                @error('invoiceDescription')
                                    <span class="text-sm text-red-500">{{ $message }}</span>
                                @enderror
                            </label>

                            @if ($invoiceKind === 'recurring')
                                <label class="flex items-start gap-2 text-sm">
                                    <input id="invoiceChargeNow" type="checkbox" wire:model.live="invoiceChargeNow" class="mt-1 rounded">
                                    <span>
                                        <span class="font-medium">{{ __('Charge this amount today') }}</span>
                                        <span class="mt-0.5 block text-[11px] leading-4 text-neutral-500 dark:text-fg-dim">
                                            {{ __('Creates a one-time invoice for the add-on. Turn off to only raise the monthly total from the next cycle.') }}
                                        </span>
                                    </span>
                                </label>
                            @endif

                            <div class="flex flex-wrap gap-2">
                                <button type="submit" class="button button-highlighted"
                                    wire:loading.attr="disabled" wire:target="submitInvoice">
                                    {{ $invoiceKind === 'recurring' && ! $invoiceChargeNow ? __('Update monthly charge') : __('Create invoice') }}
                                </button>
                                <button type="button" class="button" wire:click="cancelInvoiceForm"
                                    wire:loading.attr="disabled" wire:target="submitInvoice,cancelInvoiceForm">
                                    {{ __('Cancel') }}
                                </button>
                            </div>
                        </form>
                    </x-application.settings-section>
                @endif

                <x-application.settings-section title="{{ __('Invoices') }}" flush>
                    <p class="border-b border-neutral-200 px-4 py-3 text-[13px] leading-5 text-neutral-500 dark:border-white/[0.08] dark:text-fg-dim">
                        {{ __('Server and plan charges. Pending rows are what the client sees under Billing.') }}
                    </p>
                    @php
                        $invoiceRows = collect()
                            ->merge($orders->map(fn ($order) => [
                                'key' => 'server-'.$order->id,
                                'date' => $order->created_at,
                                'team' => $order->team?->name,
                                'item' => $order->description ?: ($order->server?->name ?: $order->server_name),
                                'amount' => $order->amount,
                                'status' => $order->statusLabel(),
                                'statusType' => $order->statusType(),
                                'kind' => __('Server'),
                            ]))
                            ->merge($planOrders->map(fn ($order) => [
                                'key' => 'plan-'.$order->id,
                                'date' => $order->created_at,
                                'team' => $order->team?->name,
                                'item' => $order->description ?: $order->plan?->name,
                                'amount' => $order->amount,
                                'status' => $order->statusLabel(),
                                'statusType' => $order->statusType(),
                                'kind' => __('Organization plan'),
                            ]))
                            ->sortByDesc(fn ($row) => $row['date']?->timestamp ?? 0)
                            ->take(100)
                            ->values();
                    @endphp
                    @if ($invoiceRows->isEmpty())
                        <x-empty size="sm" title="{{ __('No invoices yet.') }}"
                            description="{{ __('Create an invoice from Clients or Purchases.') }}" icon-name="calendar" />
                    @else
                        <div class="overflow-x-auto">
                            <div class="data-table">
                                <div class="data-table-header getodoo-owner-billing-invoices-grid">
                                    <span>{{ __('Date') }}</span>
                                    <span>{{ __('Team') }}</span>
                                    <span>{{ __('Item') }}</span>
                                    <span>{{ __('Type') }}</span>
                                    <span>{{ __('Amount') }}</span>
                                    <span>{{ __('Status') }}</span>
                                </div>
                                @foreach ($invoiceRows as $row)
                                    <div class="data-table-row getodoo-owner-billing-invoices-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.07]"
                                        wire:key="getodoo-invoice-{{ $row['key'] }}">
                                        <div class="text-[13px]">{{ $row['date']?->timezone(config('app.timezone'))->isoFormat('D MMM YYYY') }}</div>
                                        <div class="truncate text-[13px]">{{ $row['team'] }}</div>
                                        <div class="truncate text-[13px]">{{ $row['item'] }}</div>
                                        <div class="text-[13px] text-neutral-500 dark:text-fg-dim">{{ $row['kind'] }}</div>
                                        <div class="text-[13px]">${{ number_format((float) $row['amount'], 2) }}</div>
                                        <div><x-status-badge :status="$row['status']" :type="$row['statusType']" /></div>
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
