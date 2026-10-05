<div>
    <x-slot:title>
        {{ __('GetOdoo servers | Coolify') }}
    </x-slot>

    <x-settings.layout>
        <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            <x-application.settings-section title="{{ __('Hetzner account') }}"
                description="{{ __('Choose a Hetzner token. Sold servers are created in that account.') }}">
                @if ($tokens->isEmpty())
                    <p class="mb-4 text-sm text-neutral-500 dark:text-fg-faint">
                        {{ __('Add the Hetzner token first. Sold servers are created in that account.') }}
                    </p>
                    <livewire:security.cloud-provider-token-form provider="hetzner" wire:key="getodoo-hetzner-token" />
                @else
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach ($tokens as $token)
                            <button type="button" wire:key="getodoo-token-{{ $token->id }}"
                                wire:click="selectToken({{ $token->id }})"
                                @class([
                                    'flex min-h-24 flex-col rounded-xl border p-3 text-left',
                                    'border-coollabs ring-1 ring-coollabs' => (int) $tokenId === (int) $token->id,
                                    'border-neutral-200 dark:border-white/10' => (int) $tokenId !== (int) $token->id,
                                ])>
                                <span class="text-sm font-semibold">{{ $token->name }}</span>
                                <span class="mt-1 text-[11px] text-neutral-500 dark:text-fg-faint">
                                    {{ $token->description ?: __('Sold servers are created with this token.') }}
                                </span>
                            </button>
                        @endforeach
                    </div>
                    <div class="mt-4">
                        <x-modal-input title="{{ __('Add token') }}">
                            <x-slot:content>
                                <button type="button" class="button">{{ __('Add token') }}</button>
                            </x-slot:content>
                            <livewire:security.cloud-provider-token-form :modal_mode="true" provider="hetzner"
                                wire:key="getodoo-hetzner-token-modal" />
                        </x-modal-input>
                    </div>
                @endif
            </x-application.settings-section>

            <x-application.settings-section title="Wompi"
                description="{{ __('Wompi charges this monthly price before the server is created. Leave the credentials empty and the server is created as soon as it is requested.') }}">
                <form wire:submit="saveWompi" class="grid max-w-xl gap-4">
                    <label class="flex flex-col gap-1 text-sm">
                        <span>{{ __('Client ID') }}</span>
                        <input id="wompiClientId" type="text" wire:model="wompiClientId" autocomplete="off"
                            class="h-10 rounded-md border border-neutral-300 bg-white px-2 text-sm dark:border-white/15 dark:bg-transparent">
                        @error('wompiClientId')
                            <span class="text-sm text-error">{{ $message }}</span>
                        @enderror
                    </label>
                    <label class="flex flex-col gap-1 text-sm">
                        <span>{{ __('API secret') }}</span>
                        <input id="wompiClientSecret" type="password" wire:model="wompiClientSecret" autocomplete="new-password"
                            placeholder="{{ $wompiReady ? __('Leave the secret empty to keep the one already saved.') : '' }}"
                            class="h-10 rounded-md border border-neutral-300 bg-white px-2 text-sm dark:border-white/15 dark:bg-transparent">
                        @error('wompiClientSecret')
                            <span class="text-sm text-error">{{ $message }}</span>
                        @enderror
                    </label>
                    <div>
                        <button type="submit" class="button button-highlighted">{{ __('Save') }}</button>
                    </div>
                </form>
            </x-application.settings-section>

            <x-application.settings-section title="{{ __('GetOdoo servers') }}"
                description="{{ __('Fetch only the Hetzner servers that can be created right now, set a markup, and choose which ones admins can launch.') }}">
                @if ($selectedToken)
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" class="button button-highlighted" wire:click="refreshConnection"
                            wire:loading.attr="disabled" wire:target="refreshConnection">
                            {{ __('Update connection') }}
                        </button>
                        <span class="text-sm text-neutral-500 dark:text-fg-faint">
                            {{ $selectedToken->name }}
                        </span>
                    </div>
                @else
                    <p class="text-sm text-neutral-500 dark:text-fg-faint">
                        {{ __('Add the Hetzner token first. Sold servers are created in that account.') }}
                    </p>
                @endif
            </x-application.settings-section>

            <x-application.settings-section title="{{ __('Resale') }}"
                description="{{ __('Hetzner prices are euros before tax. The suggested price adds tax, the €1 IPv4, converts to dollars, then adds your percentage.') }}">
                <form wire:submit="saveOffers" class="flex flex-col gap-4">
                    <div class="grid max-w-3xl grid-cols-1 gap-4 sm:grid-cols-3">
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="inline-flex items-center gap-1">
                                {{ __('EUR to USD') }}
                                <x-helper label="{{ __('How euros become dollars') }}"
                                    :helper="e(__('You set this factor. Right now 1 euro equals :rate dollars. It is not read from a bank. The cost in dollars is the cost in euros multiplied by this number.', ['rate' => number_format((float) $eurUsd, 4)]))" />
                            </span>
                            <input id="eurUsd" type="number" min="0.0001" step="0.0001" wire:model.live="eurUsd"
                                class="h-10 rounded-md border border-neutral-300 bg-white px-2 text-sm dark:border-white/15 dark:bg-transparent">
                            <span class="text-[11px] text-neutral-500 dark:text-fg-faint">1 EUR = {{ number_format((float) $eurUsd, 4) }} USD</span>
                        </label>
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="inline-flex items-center gap-1">
                                {{ __('Tax percent') }}
                                <x-helper label="{{ __('How tax is added') }}"
                                    :helper="e(__('Hetzner adds tax on top of the server and the IPv4. The cost in euros is the Hetzner price plus 1 euro for IPv4, then :tax% tax. Nothing is subtracted.', ['tax' => number_format((float) $taxPercent, 2)]))" />
                            </span>
                            <input id="taxPercent" type="number" min="0" step="0.01" wire:model.live="taxPercent"
                                class="h-10 rounded-md border border-neutral-300 bg-white px-2 text-sm dark:border-white/15 dark:bg-transparent">
                        </label>
                        <label class="flex flex-col gap-1 text-sm">
                            <span class="inline-flex items-center gap-1">
                                {{ __('Margin') }}
                                <x-helper label="{{ __('How the margin changes the price') }}"
                                    :helper="e(__('This global margin updates lines that are not published. A published line keeps its own margin. Turn off Available for admins before changing that line.'))" />
                            </span>
                            <input id="marginPercent" type="number" min="0" step="0.01" wire:model.live="marginPercent"
                                class="h-10 rounded-md border border-neutral-300 bg-white px-2 text-sm dark:border-white/15 dark:bg-transparent">
                        </label>
                    </div>
                    <p class="text-[11px] leading-5 text-neutral-500 dark:text-fg-faint">
                        {{ __('Cost in euros is the Hetzner price plus €1 for IPv4, then tax. Dollars are that cost times the factor. The suggested price adds your percentage on top.') }}
                        {{ __('A published line keeps its margin. The global margin only changes lines that are not available for admins.') }}
                    </p>
                    @if ($offers->isEmpty())
                        <p class="text-sm text-neutral-500 dark:text-fg-faint">
                            {{ __('No Hetzner servers yet. Update the connection to fetch them.') }}
                        </p>
                    @else
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[52rem] text-left text-sm">
                                <thead>
                                    <tr class="border-b border-neutral-200 text-[11px] tracking-wide text-neutral-500 uppercase dark:border-white/10 dark:text-fg-faint">
                                        <th class="px-2 py-2 font-medium">{{ __('Server') }}</th>
                                        <th class="px-2 py-2 font-medium">
                                            <span class="inline-flex items-center gap-1">
                                                {{ __('Hetzner price') }}
                                                <x-helper label="{{ __('What the Hetzner price is') }}"
                                                    :helper="e(__('The Hetzner price in euros, before tax and before the IPv4 charge.'))" />
                                            </span>
                                        </th>
                                        <th class="px-2 py-2 font-medium">
                                            <span class="inline-flex items-center gap-1">
                                                {{ __('Cost in euros') }}
                                                <x-helper label="{{ __('What is added in euros') }}"
                                                    :helper="e(__('Adds 1 euro for IPv4, then adds :tax% tax on that sum. Nothing is subtracted.', ['tax' => number_format((float) $taxPercent, 2)]))" />
                                            </span>
                                        </th>
                                        <th class="px-2 py-2 font-medium">
                                            <span class="inline-flex items-center gap-1">
                                                {{ __('Cost in dollars') }}
                                                <x-helper label="{{ __('How euros become dollars') }}"
                                                    :helper="e(__('You set this factor. Right now 1 euro equals :rate dollars. It is not read from a bank. The cost in dollars is the cost in euros multiplied by this number.', ['rate' => number_format((float) $eurUsd, 4)]))" />
                                            </span>
                                        </th>
                                        <th class="px-2 py-2 font-medium">
                                            <span class="inline-flex items-center gap-1">
                                                {{ __('Suggested price') }}
                                                <x-helper label="{{ __('How the margin changes the price') }}"
                                                    :helper="e(__('Each line can use its own margin. Once it is available for admins, that margin stays until you turn the line off.'))" />
                                            </span>
                                        </th>
                                        <th class="px-2 py-2 font-medium">{{ __('Available for admins') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($offers as $offer)
                                        @php
                                            $published = (bool) ($available[$offer->id] ?? false) && $offer->in_stock;
                                            $lineMargin = (float) ($lineMargins[$offer->id] ?? $marginPercent);
                                            $sameMargin = $offer->margin_percent !== null && abs((float) $offer->margin_percent - $lineMargin) < 0.01;
                                        @endphp
                                        <tr @class([
                                            'border-b border-neutral-100 dark:border-white/5',
                                            'getodoo-offer-published' => $published,
                                        ]) style="{{ $published ? 'background: rgba(47, 196, 122, 0.16); box-shadow: inset 3px 0 0 #2fc47a;' : '' }}" wire:key="getodoo-offer-{{ $offer->id }}">
                                            <td class="px-2 py-3">
                                                <div class="font-medium text-black dark:text-fg">{{ $offer->description ?: $offer->name }}</div>
                                                <div class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                                    {{ $offer->cores }} vCPU · {{ (float) $offer->memory }} GB · {{ $offer->disk }} GB
                                                    @unless ($offer->in_stock)
                                                        · {{ __('Out of stock') }}
                                                    @endunless
                                                </div>
                                            </td>
                                            <td class="px-2 py-3">{{ number_format((float) $offer->monthly_price, 2) }} EUR</td>
                                            <td class="px-2 py-3">{{ number_format($pricing->costEur((float) $offer->monthly_price), 2) }} EUR</td>
                                            <td class="px-2 py-3">{{ number_format($pricing->costUsd((float) $offer->monthly_price), 2) }} USD</td>
                                            <td class="px-2 py-3 font-medium">
                                                {{ number_format($this->suggestedUsd((float) $offer->monthly_price, $offer->id), 2) }} USD
                                                <label class="mt-1 flex items-center gap-1 text-[11px] font-normal text-neutral-500 dark:text-fg-faint">
                                                    <span>{{ __('Margin') }}</span>
                                                    <input id="margin-{{ $offer->id }}" type="number" min="0" step="0.01"
                                                        wire:model.live="lineMargins.{{ $offer->id }}" @disabled($published)
                                                        title="{{ $published ? __('Uncheck Available for admins before changing this margin.') : '' }}"
                                                        class="h-8 w-20 rounded-md border border-neutral-300 bg-white px-2 text-sm text-black disabled:cursor-not-allowed disabled:opacity-70 dark:border-white/15 dark:bg-transparent dark:text-fg">
                                                    <span>%</span>
                                                </label>
                                            </td>
                                            <td class="px-2 py-3">
                                                <div class="flex items-center gap-2">
                                                    <input id="available-{{ $offer->id }}" type="checkbox"
                                                        wire:model.live="available.{{ $offer->id }}" @disabled(! $offer->in_stock)
                                                        class="size-4 rounded border-neutral-300">
                                                    @if ($published)
                                                        <span class="inline-flex items-center gap-1.5 text-[11px] font-medium" style="color: #1f9d57;">
                                                            <span class="getodoo-published-light" aria-hidden="true" style="display: inline-block; width: 0.55rem; height: 0.55rem; border-radius: 999px; background: #2fc47a; box-shadow: 0 0 0 3px rgba(47, 196, 122, 0.35);"></span>
                                                            {{ __('Published') }}
                                                        </span>
                                                    @endif
                                                </div>
                                                @if ($published && $offer->available_since && $sameMargin)
                                                    <div class="mt-1 text-[11px] text-neutral-500 dark:text-fg-faint">
                                                        {{ __('Available since :date', ['date' => $offer->available_since->timezone(config('app.timezone'))->locale(app()->getLocale())->isoFormat('D MMM YYYY, HH:mm')]) }}
                                                    </div>
                                                @elseif ($published)
                                                    <div class="mt-1 text-[11px] text-neutral-500 dark:text-fg-faint">
                                                        {{ __('The date is saved when you publish.') }}
                                                    </div>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    <div>
                        <button type="submit" class="button button-highlighted">{{ __('Save') }}</button>
                    </div>
                </form>
            </x-application.settings-section>
        </div>
    </x-settings.layout>
</div>
