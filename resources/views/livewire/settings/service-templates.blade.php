<div>
    <x-slot:title>
        {{ __('Service templates | Coolify') }}
    </x-slot>

    <x-settings.layout>
        <div class="flex w-full min-w-0 flex-col gap-6">
            <x-application.settings-section title="{{ __('Service templates') }}">
                <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                    {{ __('The owner edits the Compose used the next time anyone creates this service. A service that already exists keeps its own copy.') }}
                </p>
                <div class="grid gap-4 lg:grid-cols-[16rem_minmax(0,1fr)]">
                    <div class="flex min-w-0 flex-col gap-2">
                        <input type="search" wire:model.live="search" placeholder="{{ __('Search templates') }}"
                            class="input" autocomplete="off">
                        <div
                            class="max-h-[32rem] overflow-auto rounded-lg border border-neutral-200 dark:border-white/[0.08]">
                            @forelse ($services as $service)
                                <button type="button" wire:key="template-{{ $service['name'] }}"
                                    wire:click="selectService(@js($service['name']))"
                                    @class([
                                        'flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-[13px] hover:bg-neutral-50 dark:hover:bg-white/[0.04]',
                                        'bg-neutral-100 dark:bg-white/[0.06]' => $serviceName === $service['name'],
                                    ])>
                                    <span class="truncate">{{ $service['name'] }}</span>
                                    @if ($service['overridden'])
                                        <span
                                            class="shrink-0 text-[11px] text-neutral-400 dark:text-fg-faint">{{ __('Edited') }}</span>
                                    @endif
                                </button>
                            @empty
                                <p class="px-3 py-4 text-[13px] text-neutral-500 dark:text-fg-dim">
                                    {{ __('No templates match.') }}
                                </p>
                            @endforelse
                        </div>
                    </div>

                    <div class="min-w-0">
                        @if ($serviceName)
                            <form wire:submit="save" class="flex flex-col gap-4">
                                <x-forms.textarea id="compose" :label="$serviceName" rows="22" monospace allowTab
                                    required
                                    helper="{{ __('This Compose is copied when a member creates the service. Redeploying an existing service does not pick up this change.') }}" />
                                <div class="flex flex-wrap justify-end gap-2">
                                    @if ($overridden)
                                        <button type="button" wire:click="restore" class="button">
                                            {{ __('Restore catalog version') }}
                                        </button>
                                    @endif
                                    <button type="submit" class="button button-highlighted">
                                        {{ __('Save template') }}
                                    </button>
                                </div>
                            </form>
                        @else
                            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                {{ __('Choose a template to edit its Compose.') }}
                            </p>
                        @endif
                    </div>
                </div>
            </x-application.settings-section>
        </div>
    </x-settings.layout>
</div>
