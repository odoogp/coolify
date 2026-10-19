<div>
    @if (session('error'))
        <span x-data x-init="$wire.dispatch('error', @js(session('error')))"></span>
    @endif
    @if ($offers->isEmpty())
        <x-application.settings-section title="{{ __('GetOdoo server') }}"
            description="{{ __('No GetOdoo servers are available yet.') }}">
            @if (isInstanceOwner())
                <a href="{{ route('settings.getodoo-servers') }}" class="button button-highlighted" {{ wireNavigate() }}>
                    {{ __('Manage resale') }}
                </a>
            @endif
        </x-application.settings-section>
    @else
        <form wire:submit="submit" class="flex flex-col gap-6">
            <x-application.settings-section title="{{ __('GetOdoo server') }}"
                description="{{ __('Choose a GetOdoo server. Ubuntu is installed and your SSH key is added.') }}">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    @foreach ($offers as $offer)
                        <label wire:key="getodoo-choice-{{ $offer->id }}"
                            class="getodoo-choice flex cursor-pointer flex-col gap-3 rounded-xl border p-3 {{ (int) $offerId === (int) $offer->id ? 'is-selected' : '' }}">
                            <span class="flex items-start justify-between gap-3">
                                <span class="flex min-w-0 items-start gap-2">
                                    <img src="{{ product_mark_url() }}" alt="{{ product_name() }}" class="size-8 shrink-0 object-contain">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold">{{ $offer->description ?: $offer->name }}</span>
                                        <span class="mt-0.5 block text-[11px] leading-4 text-neutral-500 dark:text-fg-faint">{{ $offer->customerSummary() }}</span>
                                    </span>
                                </span>
                                <input type="radio" class="sr-only" wire:model.live="offerId" value="{{ $offer->id }}">
                                <span class="shrink-0 text-right">
                                    <span class="block text-sm font-semibold">{{ number_format($offer->sellPrice((int) $offerId === (int) $offer->id ? $location : $offer->location), 2) }} USD</span>
                                    <span class="mt-0.5 block text-[11px] text-neutral-500 dark:text-fg-faint">{{ __('per month') }}</span>
                                </span>
                            </span>
                            <span class="flex gap-2">
                                <span class="flex min-w-0 flex-1 items-center gap-2">
                                    <x-reicon name="cpu" class="size-4 shrink-0 text-neutral-500 dark:text-fg-faint" />
                                    <span class="min-w-0">
                                        <span class="block text-[11px] text-neutral-500 dark:text-fg-faint">{{ __('CPU') }}</span>
                                        <span class="block text-sm font-semibold">{{ $offer->cores }} vCPU</span>
                                    </span>
                                </span>
                                <span class="flex min-w-0 flex-1 items-center gap-2">
                                    <x-reicon name="memory" class="size-4 shrink-0 text-neutral-500 dark:text-fg-faint" />
                                    <span class="min-w-0">
                                        <span class="block text-[11px] text-neutral-500 dark:text-fg-faint">{{ __('Memory') }}</span>
                                        <span class="block text-sm font-semibold">{{ (float) $offer->memory }} GB</span>
                                    </span>
                                </span>
                                <span class="flex min-w-0 flex-1 items-center gap-2">
                                    <x-reicon name="disk" class="size-4 shrink-0 text-neutral-500 dark:text-fg-faint" />
                                    <span class="min-w-0">
                                        <span class="block text-[11px] text-neutral-500 dark:text-fg-faint">{{ __('Disk') }}</span>
                                        <span class="block text-sm font-semibold">{{ $offer->disk }} GB</span>
                                    </span>
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('offerId')
                    <p class="mt-2 text-sm text-error">{{ $message }}</p>
                @enderror
            </x-application.settings-section>

            <x-application.settings-section title="{{ __('Launch') }}"
                description="{{ $paymentProvider === 'n1co' ? __('The GetOdoo server is created after n1co approves this monthly charge.') : ($paymentProvider === 'wompi' ? __('The GetOdoo server is created after Wompi approves this monthly charge.') : __('The server is created from the GetOdoo connection.')) }}">
                <div class="grid max-w-xl gap-4">
                    <x-forms.input id="server_name" label="{{ __('Server name') }}" required />
                    @if ($selectedOffer && count($selectedOffer->locations ?? []) > 1)
                        <x-forms.listbox id="location" label="{{ __('Location') }}" required
                            :options="collect($selectedOffer->locations)->map(fn ($row) => ['value' => $row['location'], 'label' => $row['location']])->values()->all()" />
                    @endif
                    @if ($private_keys->isEmpty())
                        <div>
                            <label class="mb-1.5 flex w-fit items-center gap-1.5">{{ __('Private key') }}
                                <x-highlighted text="*" />
                            </label>
                            <div class="flex min-h-8 items-center justify-between gap-3 rounded-lg border border-warning/30 bg-warning/5 px-3 py-2">
                                <span class="text-[11px] text-neutral-600 dark:text-fg-dim">{{ __('A private key is required.') }}</span>
                                <x-modal-input title="{{ __('New Private Key') }}" :notify-closed="false">
                                    <x-slot:content>
                                        <button type="button" class="button">{{ __('Create key') }}</button>
                                    </x-slot:content>
                                    <livewire:security.private-key.create :modal_mode="true" from="server" />
                                </x-modal-input>
                            </div>
                        </div>
                    @else
                        <div class="flex flex-col gap-2">
                            <x-forms.listbox id="private_key_id" label="{{ __('Private key') }}" required
                                placeholder="{{ __('Select a private key') }}" :options="$privateKeyOptions"
                                helper="{{ __('This key is installed on the new server.') }}" />
                            <x-private-key.download :keys="$private_keys" :selected="$private_key_id" />
                        </div>
                    @endif
                    @if ($paymentProvider)
                        <div>
                            <p class="mb-2 text-sm font-medium">{{ __('Payment method') }}</p>
                            <x-getodoo.payment-methods :gateways="[$paymentProvider]" />
                        </div>
                    @endif
                    <div>
                        <button type="submit" class="button button-highlighted" @disabled($limit_reached || ! $private_key_id)>
                            {{ $paymentProvider === 'n1co' ? __('Pay with n1co') : ($paymentProvider === 'wompi' ? __('Pay with Wompi') : __('Buy and create')) }}
                        </button>
                    </div>
                </div>
            </x-application.settings-section>
        </form>
    @endif
</div>
