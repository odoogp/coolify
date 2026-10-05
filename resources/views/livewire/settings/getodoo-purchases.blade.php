<div>
    <x-slot:title>
        {{ __('Purchases | Coolify') }}
    </x-slot>

    <x-settings.layout>
        <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <h1 class="text-[24px]! leading-7! font-semibold! tracking-tight!">{{ __('Purchases') }}</h1>

            <x-application.settings-section title="{{ __('Subscriptions') }}">
                <p class="mb-4 text-sm text-neutral-500 dark:text-fg-faint">
                    {{ __('These are the GetOdoo servers customers already have. The next payment uses the monthly price saved here.') }}
                </p>
                @if ($subscriptions->isEmpty())
                    <p class="text-sm text-neutral-500 dark:text-fg-faint">{{ __('No subscriptions yet.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[48rem] text-left text-sm">
                            <thead>
                                <tr class="border-b border-neutral-200 dark:border-white/10">
                                    <th class="px-2 py-2 font-medium">{{ __('Team') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Server') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Monthly price') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Next billing') }}</th>
                                    <th class="px-2 py-2 font-medium"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($subscriptions as $server)
                                    <tr class="border-b border-neutral-100 dark:border-white/5" wire:key="getodoo-purchase-{{ $server->id }}">
                                        <td class="px-2 py-3">{{ $server->team?->name }}</td>
                                        <td class="px-2 py-3">
                                            <div class="font-medium">{{ $server->name }}</div>
                                            <div class="text-xs text-neutral-500 dark:text-fg-faint">
                                                {{ $server->getodooOffer?->description ?: $server->getodooOffer?->name }}
                                            </div>
                                        </td>
                                        <td class="px-2 py-3">
                                            <input id="getodoo-price-{{ $server->id }}" type="number" step="0.01" min="0.01"
                                                class="input w-28" wire:model="prices.{{ $server->id }}">
                                            @error('prices.'.$server->id)
                                                <div class="mt-1 text-xs text-red-500">{{ $message }}</div>
                                            @enderror
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
                                        <td class="px-2 py-3 text-right">
                                            <button type="button" class="button" wire:click="savePrice({{ $server->id }})"
                                                wire:loading.attr="disabled" wire:target="savePrice">
                                                {{ __('Save price') }}
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
                        <table class="w-full min-w-[48rem] text-left text-sm">
                            <thead>
                                <tr class="border-b border-neutral-200 dark:border-white/10">
                                    <th class="px-2 py-2 font-medium">{{ __('Date') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Team') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Server') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Amount') }}</th>
                                    <th class="px-2 py-2 font-medium">{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($orders as $order)
                                    <tr class="border-b border-neutral-100 dark:border-white/5" wire:key="getodoo-purchase-order-{{ $order->id }}">
                                        <td class="px-2 py-3">{{ $order->created_at?->timezone(config('app.timezone'))->isoFormat('D MMM YYYY') }}</td>
                                        <td class="px-2 py-3">{{ $order->team?->name }}</td>
                                        <td class="px-2 py-3">{{ $order->server?->name ?: $order->server_name }}</td>
                                        <td class="px-2 py-3">${{ number_format((float) $order->amount, 2) }}</td>
                                        <td class="px-2 py-3">{{ $order->statusLabel() }}</td>
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
