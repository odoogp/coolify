<div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
    <x-slot:title>
        {{ __('Billing | Coolify') }}
    </x-slot>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">{{ __('Billing') }}</h1>
                <p class="mt-1 max-w-2xl text-[13px] leading-5 text-neutral-500 dark:text-fg-dim">
                    {{ __('Each server is one subscription. Its payments stay inside, and the next charge keeps the day the first payment was processed.') }}
                </p>
            </div>
            <a href="{{ route('server.index') }}" {{ wireNavigate() }} class="button w-fit shrink-0 whitespace-nowrap">
                <x-reicon name="servers" class="size-3.5" />
                {{ __('Servers') }}
            </a>
        </div>

        @if ($groups->isEmpty())
            <x-application.settings-section title="{{ __('Subscriptions') }}" flush>
                <x-empty size="sm" title="{{ __('No subscriptions yet.') }}"
                    description="{{ __('A GetOdoo server purchase appears here as its own subscription.') }}"
                    icon-name="servers" />
            </x-application.settings-section>
        @else
            @foreach ($groups as $group)
                @php
                    $server = $group['server'];
                    $pending = $server
                        ? $group['orders']->first(function ($order) use ($server) {
                            return $order->purpose === 'renewal'
                                && $order->status === 'awaiting_payment'
                                && filled($order->wompi_link_url)
                                && number_format((float) $order->amount, 2, '.', '') === number_format((float) $server->getodoo_monthly_price, 2, '.', '');
                        })
                        : null;
                    $overdue = $server?->getodoo_paid_until && $server->getodoo_paid_until->copy()->endOfDay()->isPast();
                    $headline = $group['orders']->first();
                @endphp
                <x-application.settings-section :title="$group['name']" flush wire:key="getodoo-subscription-{{ $group['key'] }}">
                    <x-slot:actions>
                        @if ($server)
                            <button type="button" class="button button-highlighted" wire:click="pay({{ $server->id }})"
                                wire:loading.attr="disabled" wire:target="pay,cancelPlan">
                                {{ $pending ? __('Continue payment') : __('Pay') }}
                            </button>
                            <button type="button" class="button" wire:click="cancelPlan({{ $server->id }})"
                                wire:confirm="{{ __('Cancel this plan? The server will be deleted.') }}"
                                wire:loading.attr="disabled" wire:target="cancelPlan">
                                {{ __('Cancel') }}
                            </button>
                        @elseif ($headline)
                            <x-status-badge :status="$headline->statusLabel()" :type="$headline->statusType()" />
                        @endif
                    </x-slot:actions>

                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-neutral-200 px-4 py-3 text-[13px] text-neutral-600 dark:border-white/[0.08] dark:text-fg-dim">
                        <span>{{ $group['offer'] ?: __('Server') }}</span>
                        <span>${{ number_format($group['price'], 2) }}</span>
                        @if ($server?->getodoo_paid_until)
                            <span>{{ __('Next billing') }} {{ $server->getodoo_paid_until->isoFormat('D MMM YYYY') }}</span>
                            @if ($overdue)
                                <x-status-badge status="{{ __('Overdue') }}" type="error" />
                            @endif
                        @elseif ($server)
                            <span>{{ __('Not billed yet') }}</span>
                        @endif
                    </div>

                    @if ($group['orders']->isEmpty())
                        <x-empty size="sm" title="{{ __('No purchases yet.') }}"
                            description="{{ __('Payments for this server appear here.') }}" icon-name="calendar" />
                    @else
                        <div class="overflow-x-auto">
                            <div class="data-table">
                                <div class="data-table-header getodoo-billing-table-grid">
                                    <span>{{ __('Date') }}</span>
                                    <span>{{ __('Amount') }}</span>
                                    <span>{{ __('Status') }}</span>
                                    <span></span>
                                </div>
                                @foreach ($group['orders'] as $order)
                                    <div class="data-table-row getodoo-billing-table-grid border-b border-neutral-200 last:border-b-0 dark:border-white/[0.08]"
                                        wire:key="getodoo-order-{{ $order->id }}">
                                        <div class="text-[13px] text-neutral-700 dark:text-fg">
                                            {{ $order->created_at?->timezone(config('app.timezone'))->isoFormat('D MMM YYYY') }}
                                        </div>
                                        <div class="text-[13px] text-neutral-700 dark:text-fg">
                                            ${{ number_format((float) $order->amount, 2) }}
                                        </div>
                                        <div>
                                            <x-status-badge :status="$order->statusLabel()" :type="$order->statusType()" />
                                        </div>
                                        <div class="flex justify-end gap-2">
                                            @if (! $server && in_array($order->status, ['awaiting_payment', 'failed'], true))
                                                <button type="button" class="button button-highlighted" wire:click="payOrder({{ $order->id }})"
                                                    wire:loading.attr="disabled" wire:target="payOrder,cancelOrder">
                                                    {{ __('Pay') }}
                                                </button>
                                                <button type="button" class="button" wire:click="cancelOrder({{ $order->id }})"
                                                    wire:confirm="{{ __('Cancel this purchase?') }}"
                                                    wire:loading.attr="disabled" wire:target="cancelOrder">
                                                    {{ __('Cancel') }}
                                                </button>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </x-application.settings-section>
            @endforeach
        @endif
</div>
