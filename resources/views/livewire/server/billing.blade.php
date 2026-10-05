<div>
    <x-slot:title>
        {{ __('Billing | Coolify') }}
    </x-slot>

    <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h1 class="min-w-0 text-[24px]! leading-7! font-semibold! tracking-tight!">{{ __('Billing') }}</h1>
            <a href="{{ route('server.index') }}" {{ wireNavigate() }} class="button w-fit shrink-0 whitespace-nowrap">
                {{ __('Servers') }}
            </a>
        </div>

        <x-application.settings-section title="{{ __('Subscriptions') }}">
            <p class="mb-4 text-sm text-neutral-500 dark:text-fg-faint">
                {{ __('Every subscription is charged on its next billing date, on the same day the first payment was processed. The pay button opens a Wompi charge for the price saved on that server.') }}
            </p>
            @if ($subscriptions->isEmpty())
                <p class="text-sm text-neutral-500 dark:text-fg-faint">{{ __('No subscriptions yet.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-left text-sm">
                        <thead>
                            <tr class="border-b border-neutral-200 dark:border-white/10">
                                <th class="px-2 py-2 font-medium">{{ __('Server') }}</th>
                                <th class="px-2 py-2 font-medium">{{ __('Monthly price') }}</th>
                                <th class="px-2 py-2 font-medium">{{ __('Next billing') }}</th>
                                <th class="px-2 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($subscriptions as $server)
                                @php
                                    $pending = $orders->first(function ($order) use ($server) {
                                        return $order->purpose === 'renewal'
                                            && $order->status === 'awaiting_payment'
                                            && (int) $order->server_id === (int) $server->id
                                            && filled($order->wompi_link_url)
                                            && number_format((float) $order->amount, 2, '.', '') === number_format((float) $server->getodoo_monthly_price, 2, '.', '');
                                    });
                                    $overdue = $server->getodoo_paid_until && $server->getodoo_paid_until->copy()->endOfDay()->isPast();
                                @endphp
                                <tr class="border-b border-neutral-100 dark:border-white/5" wire:key="getodoo-subscription-{{ $server->id }}">
                                    <td class="px-2 py-3">
                                        <a href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}" {{ wireNavigate() }} class="font-medium">
                                            {{ $server->name }}
                                        </a>
                                        <div class="text-xs text-neutral-500 dark:text-fg-faint">
                                            {{ $server->getodooOffer?->description ?: $server->getodooOffer?->name }}
                                        </div>
                                    </td>
                                    <td class="px-2 py-3">${{ number_format((float) $server->getodoo_monthly_price, 2) }}</td>
                                    <td class="px-2 py-3">
                                        @if ($server->getodoo_paid_until)
                                            <span>{{ $server->getodoo_paid_until->isoFormat('D MMM YYYY') }}</span>
                                            @if ($overdue)
                                                <span class="ml-2 text-xs font-medium text-red-500">{{ __('Overdue') }}</span>
                                            @endif
                                        @else
                                            {{ __('Not billed yet') }}
                                        @endif
                                    </td>
                                    <td class="px-2 py-3 text-right">
                                        <button type="button" class="button button-highlighted" wire:click="pay({{ $server->id }})"
                                            wire:loading.attr="disabled" wire:target="pay">
                                            {{ $pending ? __('Continue payment') : __('Pay') }}
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-application.settings-section>

        <x-application.settings-section title="{{ __('Transactions') }}">
            @if ($orders->isEmpty())
                <p class="text-sm text-neutral-500 dark:text-fg-faint">{{ __('No purchases yet.') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[40rem] text-left text-sm">
                        <thead>
                            <tr class="border-b border-neutral-200 dark:border-white/10">
                                <th class="px-2 py-2 font-medium">{{ __('Date') }}</th>
                                <th class="px-2 py-2 font-medium">{{ __('Server') }}</th>
                                <th class="px-2 py-2 font-medium">{{ __('Amount') }}</th>
                                <th class="px-2 py-2 font-medium">{{ __('Status') }}</th>
                                <th class="px-2 py-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($orders as $order)
                                <tr class="border-b border-neutral-100 dark:border-white/5" wire:key="getodoo-order-{{ $order->id }}">
                                    <td class="px-2 py-3">{{ $order->created_at?->timezone(config('app.timezone'))->isoFormat('D MMM YYYY') }}</td>
                                    <td class="px-2 py-3">{{ $order->server?->name ?: $order->server_name }}</td>
                                    <td class="px-2 py-3">${{ number_format((float) $order->amount, 2) }}</td>
                                    <td class="px-2 py-3">{{ $order->statusLabel() }}</td>
                                    <td class="px-2 py-3 text-right">
                                        @if (in_array($order->status, ['awaiting_payment', 'failed'], true))
                                            <button type="button" class="button button-highlighted" wire:click="payOrder({{ $order->id }})"
                                                wire:loading.attr="disabled" wire:target="payOrder">
                                                {{ __('Pay') }}
                                            </button>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-application.settings-section>
    </div>
</div>
