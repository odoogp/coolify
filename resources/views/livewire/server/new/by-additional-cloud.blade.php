<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @elseif ($current_step === 1)
        <x-server.provider-token-picker :provider="$provider" :providerLabel="$providerDefinition['label']"
            :routeType="$provider" :tokens="$available_tokens" />
    @elseif ($current_step === 2)
        <div wire:init="loadCatalog">
            @if ($loading_data)
                <x-application.settings-section title="{{ __('Loading') }} {{ $providerDefinition['label'] }}"
                    description="{{ __('Fetching regions, plans, and images.') }}">
                    <div class="flex min-h-40 items-center justify-center">
                        <x-loading text="{{ __('Loading provider data...') }}" />
                    </div>
                </x-application.settings-section>
            @elseif ($provider_data_error)
                <x-application.settings-section title="{{ __('Unable to load this provider') }}"
                    description="{{ __('The selected credential could not access the provider API.') }}">
                    <x-callout type="error" title="{{ __('Provider request failed') }}">
                        <pre class="mt-2 whitespace-pre-wrap break-words text-[11px]">{{ $provider_data_error }}</pre>
                    </x-callout>
                    <div class="mt-4">
                        <a class="button" href="{{ route('server.create.type', ['type' => $provider]) }}"
                            {{ wireNavigate() }}>{{ __('Select another credential') }}</a>
                    </div>
                </x-application.settings-section>
            @else
                @php
                    $regionOptions = collect($regions)->map(fn ($region) => [
                        'value' => $region['id'],
                        'label' => $region['label'],
                    ])->values()->all();
                    $planOptions = collect($plans)->map(fn ($plan) => [
                        'value' => $plan['id'],
                        'label' => $plan['label'],
                    ])->values()->all();
                    $imageOptions = collect($images)->map(fn ($image) => [
                        'value' => $image['id'],
                        'label' => $image['label'],
                    ])->values()->all();
                    $privateKeyOptions = $private_keys->map(fn ($key) => [
                        'value' => $key->id,
                        'label' => $key->name,
                    ])->values()->all();
                @endphp

                <form wire:submit="submit" class="flex flex-col gap-6">
                    <x-application.settings-section title="{{ $providerDefinition['label'] }}"
                        description="{{ __('Choose the region, plan, image, and Coolify SSH key.') }}">
                        <x-slot:actions>
                            <button type="submit" class="button button-highlighted" @disabled(!$private_key_id)>
                                {{ __('Buy and create') }}
                            </button>
                        </x-slot:actions>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div class="lg:col-span-2">
                                <x-forms.input id="server_name" label="{{ __('Server name') }}"
                                    helper="{{ __('A friendly name shown in Coolify.') }}" />
                            </div>
                            <x-forms.listbox id="selected_region" label="{{ __('Region') }}" required live
                                placeholder="{{ __('Select a region') }}" :options="$regionOptions" />
                            <x-forms.listbox id="selected_plan" label="{{ __('Plan') }}" required
                                :disabled="!$selected_region" placeholder="{{ __('Select a plan') }}"
                                :options="$planOptions" />
                            <x-forms.listbox id="selected_image" label="{{ __('Image') }}" required
                                :disabled="!$selected_plan" placeholder="{{ __('Select an image') }}"
                                :options="$imageOptions" />
                            @if ($private_keys->isEmpty())
                                <div>
                                    <label class="mb-1.5 flex w-fit items-center gap-1.5">{{ __('Private key') }}
                                        <x-highlighted text="*" />
                                    </label>
                                    <div
                                        class="flex min-h-8 items-center justify-between gap-3 rounded-lg border border-warning/30 bg-warning/5 px-3 py-2">
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
                        </div>
                    </x-application.settings-section>
                </form>
            @endif
        </div>
    @endif
</div>
