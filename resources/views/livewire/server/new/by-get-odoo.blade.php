<div>
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
                            class="getodoo-choice flex cursor-pointer flex-col gap-2 rounded-xl border p-3 {{ (int) $offerId === (int) $offer->id ? 'is-selected' : '' }}">
                            <span class="flex items-center justify-between gap-3">
                                <span class="flex items-center gap-2">
                                    <img src="{{ asset('gpsh-logo.svg') }}" alt="GetOdoo" class="size-8">
                                    <span class="text-sm font-semibold">{{ $offer->description ?: $offer->name }}</span>
                                </span>
                                <input type="radio" class="sr-only" wire:model.live="offerId" value="{{ $offer->id }}">
                                <span class="text-sm font-semibold">{{ number_format($offer->sellPrice((int) $offerId === (int) $offer->id ? $location : $offer->location), 2) }} {{ $offer->currency }}</span>
                            </span>
                            <span class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                {{ $offer->cores }} vCPU · {{ (float) $offer->memory }} GB · {{ $offer->disk }} GB · {{ __('per month') }}
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('offerId')
                    <p class="mt-2 text-sm text-error">{{ $message }}</p>
                @enderror
            </x-application.settings-section>

            <x-application.settings-section title="{{ __('Launch') }}"
                description="{{ __('The server is created from the GetOdoo connection.') }}">
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
                        <x-forms.listbox id="private_key_id" label="{{ __('Private key') }}" required
                            placeholder="{{ __('Select a private key') }}" :options="$privateKeyOptions"
                            helper="{{ __('This key is installed on the new server.') }}" />
                    @endif
                    <div>
                        <button type="submit" class="button button-highlighted" @disabled($limit_reached || ! $private_key_id)>
                            {{ __('Buy and create') }}
                        </button>
                    </div>
                </div>
            </x-application.settings-section>
        </form>
    @endif
</div>
