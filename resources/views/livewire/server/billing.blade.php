<div>
    <x-slot:title>
        {{ __('Billing | Coolify') }}
    </x-slot>

    <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">{{ __('Billing') }}</h1>
                <p class="mt-2 max-w-2xl text-sm text-neutral-500 dark:text-fg-faint">
                    {{ __('Each server is one subscription. Its payments stay inside, and the next charge keeps the day the first payment was processed.') }}
                </p>
            </div>
            <a href="{{ route('server.index') }}" {{ wireNavigate() }} class="button w-fit shrink-0 whitespace-nowrap">
                {{ __('Servers') }}
            </a>
        </div>

        @if ($groups->isEmpty())
            <p class="text-sm text-neutral-500 dark:text-fg-faint">{{ __('No subscriptions yet.') }}</p>
        @else
            <div class="flex flex-col gap-4">
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
                    @endphp
                    <section class="overflow-hidden rounded-xl border border-neutral-200 dark:border-white/10" wire:key="getodoo-subscription-{{ $group['key'] }}">
                        <header class="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                @if ($server)
                                    <a href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}" {{ wireNavigate() }} class="text-base font-semibold">
                                        {{ $group['name'] }}
                                    </a>
                                @else
                                    <h2 class="text-base font-semibold">{{ $group['name'] }}</h2>
                                @endif
                                <p class="mt-1 text-sm text-neutral-500 dark:text-fg-faint">
                                    {{ $group['offer'] }}
                                    · ${{ number_format($group['price'], 2) }}
                                    @if ($server?->getodoo_paid_until)
                                        · {{ __('Next billing') }} {{ $server->getodoo_paid_until->isoFormat('D MMM YYYY') }}
                                        @if ($overdue)
                                            · <span class="font-medium text-red-500">{{ __('Overdue') }}</span>
                                        @endif
                                    @elseif (! $server)
                                        · {{ $group['orders']->first()?->statusLabel() }}
                                    @else
                                        · {{ __('Not billed yet') }}
                                    @endif
                                </p>
                            </div>
                            @if ($server)
                                <div class="flex shrink-0 gap-2">
                                    <button type="button" class="button button-highlighted" wire:click="pay({{ $server->id }})"
                                        wire:loading.attr="disabled" wire:target="pay,cancelPlan">
                                        {{ $pending ? __('Continue payment') : __('Pay') }}
                                    </button>
                                    <button type="button" class="button" wire:click="cancelPlan({{ $server->id }})"
                                        wire:confirm="{{ __('Cancel this plan? The server will be deleted.') }}"
                                        wire:loading.attr="disabled" wire:target="cancelPlan">
                                        {{ __('Cancel') }}
                                    </button>
                                </div>
                            @endif
                        </header>
                        @if ($group['orders']->isNotEmpty())
                            <ul class="border-t border-neutral-200 dark:border-white/10">
                                @foreach ($group['orders'] as $order)
                                    <li class="flex flex-col gap-2 border-b border-neutral-100 px-4 py-3 last:border-b-0 sm:flex-row sm:items-center sm:justify-between dark:border-white/5" wire:key="getodoo-order-{{ $order->id }}">
                                        <div class="min-w-0 text-sm">
                                            <span>{{ $order->created_at?->timezone(config('app.timezone'))->isoFormat('D MMM YYYY') }}</span>
                                            <span class="text-neutral-500 dark:text-fg-faint"> · ${{ number_format((float) $order->amount, 2) }} · {{ $order->statusLabel() }}</span>
                                        </div>
                                        @if (! $server && in_array($order->status, ['awaiting_payment', 'failed'], true))
                                            <div class="flex shrink-0 gap-2">
                                                <button type="button" class="button button-highlighted" wire:click="payOrder({{ $order->id }})"
                                                    wire:loading.attr="disabled" wire:target="payOrder,cancelOrder">
                                                    {{ __('Pay') }}
                                                </button>
                                                <button type="button" class="button" wire:click="cancelOrder({{ $order->id }})"
                                                    wire:confirm="{{ __('Cancel this purchase?') }}"
                                                    wire:loading.attr="disabled" wire:target="cancelOrder">
                                                    {{ __('Cancel') }}
                                                </button>
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endforeach
            </div>
        @endif
    </div>
</div>
