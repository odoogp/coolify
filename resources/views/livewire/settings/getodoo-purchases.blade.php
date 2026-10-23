<div>
    <x-slot:title>
        {{ __('Billing | Coolify') }}
    </x-slot>

    <x-settings.layout>
        <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <div class="min-w-0">
                <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">{{ __('Billing') }}</h1>
                <p class="mt-1 max-w-2xl text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                    {{ __('Billing center for organizations, servers you pay for, and client invoices. Only the instance owner sees this.') }}
                </p>
            </div>

            @if ($showInvoiceForm)
                <x-application.settings-section title="{{ __('Create invoice') }}" wire:key="getodoo-invoice-form">
                    <form wire:submit="submitInvoice" class="grid max-w-xl gap-4">
                        <div class="rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/[0.03]">
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
                                placeholder="{{ __('Optional — shown on the invoice') }}"
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

            <x-application.settings-section title="{{ __('Organizations') }}">
                <p class="mb-4 text-sm text-neutral-500 dark:text-fg-faint">
                    {{ __('Client organizations on a GetOdoo plan: principal, billing preference, and invoices.') }}
                </p>
                @if ($planTeams->isEmpty())
                    <p class="text-sm text-neutral-500 dark:text-fg-faint">{{ __('No organization plans yet.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[56rem] text-left text-sm">
                            <thead>
                                <tr class="border-b border-neutral-200 dark:border-white/10">
                                    <th class="px-2 py-2 font-medium">{{ __('Organization') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Principal') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Plan') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Monthly') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Preference') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Next billing') }}</th>
                                    <th class="px-2 py-2 font-medium"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($planTeams as $planTeam)
                                    @php
                                        $principal = $principals[$planTeam->id] ?? null;
                                        $isRecurring = $planTeam->prefersPlanRecurringBilling();
                                    @endphp
                                    <tr class="border-b border-neutral-100 dark:border-white/5" wire:key="getodoo-plan-team-{{ $planTeam->id }}">
                                        <td class="px-2 py-3">
                                            <div class="font-medium">{{ $planTeam->name }}</div>
                                            @if ($planTeam->members->count() > 1)
                                                <div class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                                    {{ __(':count members', ['count' => $planTeam->members->count()]) }}
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-2 py-3">
                                            @if ($principal)
                                                <div>{{ $principal->name }}</div>
                                                <div class="text-[11px] text-neutral-500 dark:text-fg-faint">{{ $principal->email }}</div>
                                            @else
                                                <span class="text-neutral-500 dark:text-fg-faint">—</span>
                                            @endif
                                        </td>
                                        <td class="px-2 py-3">{{ $planTeam->getodooPlan?->name }}</td>
                                        <td class="px-2 py-3">
                                            ${{ number_format($planTeam->getodooPlanMonthlyAmount(), 2) }}
                                            @if ((float) $planTeam->getodoo_plan_monthly_extra > 0)
                                                <div class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                                    {{ __('includes :amount extra', ['amount' => '$'.number_format((float) $planTeam->getodoo_plan_monthly_extra, 2)]) }}
                                                </div>
                                            @endif
                                        </td>
                                        <td class="px-2 py-3">
                                            @if ($isRecurring)
                                                <span class="font-medium">{{ __('Recurring') }}</span>
                                                @if ($planTeam->planRecurringBillingStartsAt())
                                                    <div class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                                        {{ __('Recurring from :date', ['date' => $planTeam->planRecurringBillingStartsAt()->isoFormat('D MMM YYYY')]) }}
                                                    </div>
                                                @endif
                                            @else
                                                {{ __('One-time') }}
                                            @endif
                                        </td>
                                        <td class="px-2 py-3">
                                            @if ($planTeam->getodoo_plan_paid_until)
                                                {{ $planTeam->getodoo_plan_paid_until->isoFormat('D MMM YYYY') }}
                                                @if ($planTeam->getodoo_plan_paid_until->copy()->endOfDay()->isPast())
                                                    <span class="ml-2 text-xs font-medium text-red-500">{{ __('Overdue') }}</span>
                                                @endif
                                            @else
                                                {{ __('Not billed yet') }}
                                            @endif
                                        </td>
                                        <td class="px-2 py-3">
                                            <div class="flex flex-wrap justify-end gap-2">
                                                @if ($isRecurring)
                                                    <button type="button" class="button"
                                                        wire:click="disablePlanRecurring({{ $planTeam->id }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="disablePlanRecurring,enablePlanRecurring,openPlanInvoiceForm">
                                                        {{ __('Cancel recurring') }}
                                                    </button>
                                                @else
                                                    <button type="button" class="button"
                                                        wire:click="enablePlanRecurring({{ $planTeam->id }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="enablePlanRecurring,disablePlanRecurring,openPlanInvoiceForm">
                                                        {{ __('Make recurring') }}
                                                    </button>
                                                @endif
                                                <button type="button" class="button button-highlighted"
                                                    wire:click="openPlanInvoiceForm({{ $planTeam->id }})"
                                                    wire:loading.attr="disabled" wire:target="openPlanInvoiceForm">
                                                    {{ __('Create invoice') }}
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-application.settings-section>

            <x-application.settings-section title="{{ __('Servers') }}">
                <p class="mb-4 text-sm text-neutral-500 dark:text-fg-faint">
                    {{ __('What you pay for each box versus which clients occupy it. Client price is what they are billed.') }}
                </p>

                @if ($instanceHost && $instanceTeams->isNotEmpty())
                    <div class="mb-6 overflow-x-auto rounded-lg border border-neutral-200 dark:border-white/10">
                        <div class="border-b border-neutral-200 bg-neutral-50 px-3 py-2 text-sm dark:border-white/10 dark:bg-white/[0.03]">
                            <div class="font-medium">{{ __('Instance host') }} — {{ $instanceHost->name }}</div>
                            <div class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                {{ __('Shared host for client projects. Cost is not billed through GetOdoo server offers.') }}
                            </div>
                        </div>
                        <table class="w-full min-w-[40rem] text-left text-sm">
                            <thead>
                                <tr class="border-b border-neutral-200 dark:border-white/10">
                                    <th class="px-2 py-2 font-medium">{{ __('Organization') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Principal') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Plan') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Members') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($instanceTeams as $instanceTeam)
                                    @php $instancePrincipal = $instancePrincipals[$instanceTeam->id] ?? null; @endphp
                                    <tr class="border-b border-neutral-100 dark:border-white/5" wire:key="instance-team-{{ $instanceTeam->id }}">
                                        <td class="px-2 py-3">{{ $instanceTeam->name }}</td>
                                        <td class="px-2 py-3">
                                            @if ($instancePrincipal)
                                                <div>{{ $instancePrincipal->name }}</div>
                                                <div class="text-[11px] text-neutral-500 dark:text-fg-faint">{{ $instancePrincipal->email }}</div>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-2 py-3">{{ $instanceTeam->getodooPlan?->name ?: '—' }}</td>
                                        <td class="px-2 py-3">
                                            {{ $instanceTeam->members->pluck('name')->filter()->implode(', ') ?: '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                @if ($serverRows->isEmpty())
                    <p class="text-sm text-neutral-500 dark:text-fg-faint">{{ __('No GetOdoo servers yet.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[64rem] text-left text-sm">
                            <thead>
                                <tr class="border-b border-neutral-200 dark:border-white/10">
                                    <th class="px-2 py-2 font-medium">{{ __('Server') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Your cost') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Client pays') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Clients') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Preference') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Next billing') }}</th>
                                    <th class="px-2 py-2 font-medium"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($serverRows as $row)
                                    @php
                                        $server = $row['server'];
                                        $isRecurring = $server->prefersRecurringBilling();
                                    @endphp
                                    <tr class="border-b border-neutral-100 dark:border-white/5" wire:key="getodoo-purchase-{{ $server->id }}">
                                        <td class="px-2 py-3">
                                            <div class="font-medium">{{ $server->name }}</div>
                                            <div class="text-xs text-neutral-500 dark:text-fg-faint">
                                                {{ $server->getodooOffer?->description ?: $server->getodooOffer?->name }}
                                            </div>
                                            <div class="mt-1 text-[11px] text-neutral-500 dark:text-fg-faint">
                                                {{ $server->team?->name }}
                                            </div>
                                        </td>
                                        <td class="px-2 py-3">
                                            @if ($row['costUsd'] !== null)
                                                ${{ number_format((float) $row['costUsd'], 2) }}
                                                <div class="text-[11px] text-neutral-500 dark:text-fg-faint">USD / {{ __('mo') }}</div>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="px-2 py-3">
                                            <input id="getodoo-price-{{ $server->id }}" type="number" step="0.01" min="0.01"
                                                class="input w-28" wire:model="prices.{{ $server->id }}">
                                            @error('prices.'.$server->id)
                                                <div class="mt-1 text-xs text-red-500">{{ $message }}</div>
                                            @enderror
                                        </td>
                                        <td class="px-2 py-3">
                                            @if ($row['principal'])
                                                <div class="font-medium">{{ $row['principal']->name }}</div>
                                                <div class="text-[11px] text-neutral-500 dark:text-fg-faint">{{ $row['principal']->email }}</div>
                                            @endif
                                            @if ($row['members']->isNotEmpty())
                                                <div class="mt-1 text-[11px] leading-4 text-neutral-500 dark:text-fg-faint">
                                                    {{ $row['members']->map(fn ($m) => $m->name ?: $m->email)->implode(', ') }}
                                                </div>
                                            @else
                                                <span class="text-neutral-500 dark:text-fg-faint">—</span>
                                            @endif
                                        </td>
                                        <td class="px-2 py-3">
                                            @if ($isRecurring)
                                                <span class="font-medium">{{ __('Recurring') }}</span>
                                                @if ($server->recurringBillingStartsAt())
                                                    <div class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                                        {{ __('Recurring from :date', ['date' => $server->recurringBillingStartsAt()->isoFormat('D MMM YYYY')]) }}
                                                    </div>
                                                @endif
                                            @else
                                                {{ __('One-time') }}
                                            @endif
                                        </td>
                                        <td class="px-2 py-3">
                                            @if ($server->getodoo_paid_until)
                                                {{ $server->getodoo_paid_until->isoFormat('D MMM YYYY') }}
                                                @if ($server->getodoo_paid_until->copy()->endOfDay()->isPast())
                                                    <span class="ml-2 text-xs font-medium text-red-500">{{ __('Overdue') }}</span>
                                                @endif
                                            @else
                                                {{ __('Not billed yet') }}
                                            @endif
                                        </td>
                                        <td class="px-2 py-3">
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
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-application.settings-section>

            <x-application.settings-section title="{{ __('Invoices') }}">
                <p class="mb-4 text-sm text-neutral-500 dark:text-fg-faint">
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
                            'kind' => __('Server'),
                        ]))
                        ->merge($planOrders->map(fn ($order) => [
                            'key' => 'plan-'.$order->id,
                            'date' => $order->created_at,
                            'team' => $order->team?->name,
                            'item' => $order->description ?: $order->plan?->name,
                            'amount' => $order->amount,
                            'status' => $order->statusLabel(),
                            'kind' => __('Organization plan'),
                        ]))
                        ->sortByDesc(fn ($row) => $row['date']?->timestamp ?? 0)
                        ->take(100)
                        ->values();
                @endphp
                @if ($invoiceRows->isEmpty())
                    <p class="text-sm text-neutral-500 dark:text-fg-faint">{{ __('No invoices yet.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[48rem] text-left text-sm">
                            <thead>
                                <tr class="border-b border-neutral-200 dark:border-white/10">
                                    <th class="px-2 py-2 font-medium">{{ __('Date') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Team') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Item') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Type') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Amount') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($invoiceRows as $row)
                                    <tr class="border-b border-neutral-100 dark:border-white/5" wire:key="getodoo-invoice-{{ $row['key'] }}">
                                        <td class="px-2 py-3">{{ $row['date']?->timezone(config('app.timezone'))->isoFormat('D MMM YYYY') }}</td>
                                        <td class="px-2 py-3">{{ $row['team'] }}</td>
                                        <td class="px-2 py-3">{{ $row['item'] }}</td>
                                        <td class="px-2 py-3">{{ $row['kind'] }}</td>
                                        <td class="px-2 py-3">${{ number_format((float) $row['amount'], 2) }}</td>
                                        <td class="px-2 py-3">{{ $row['status'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-application.settings-section>
        </div>
    </x-settings.layout>
</div>
