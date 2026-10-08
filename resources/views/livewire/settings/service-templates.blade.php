<div>
    <x-slot:title>
        {{ __('Service templates') }} | {{ product_name() }}
    </x-slot>

    <x-settings.layout>
        <div class="flex w-full min-w-0 flex-col gap-6">
            <x-application.settings-section title="{{ __('Service templates') }}">
                <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                    {{ __('Add Compose templates the platform can launch in one click. Edit Coolify seed templates or create your own. Existing services keep their own copy.') }}
                </p>
                <div class="grid gap-4 lg:grid-cols-[16rem_minmax(0,1fr)]">
                    <div class="flex min-w-0 flex-col gap-2">
                        <div class="flex gap-2">
                            <input type="search" wire:model.live="search" placeholder="{{ __('Search templates') }}"
                                class="input min-w-0 flex-1" autocomplete="off">
                            <button type="button" wire:click="startCreate" class="button button-highlighted shrink-0">
                                {{ __('New') }}
                            </button>
                        </div>
                        <div
                            class="max-h-[32rem] overflow-auto rounded-lg border border-neutral-200 dark:border-white/[0.08]">
                            @forelse ($services as $service)
                                <button type="button" wire:key="template-{{ $service['name'] }}"
                                    wire:click="selectService(@js($service['name']))"
                                    @class([
                                        'flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-[13px] hover:bg-neutral-50 dark:hover:bg-white/[0.04]',
                                        'bg-neutral-100 dark:bg-white/[0.06]' => ! $creating && $serviceName === $service['name'],
                                    ])>
                                    <span class="truncate">{{ $service['label'] }}</span>
                                    <span class="flex shrink-0 items-center gap-1 text-[11px] text-neutral-400 dark:text-fg-faint">
                                        @if ($service['is_custom'])
                                            <span>{{ __('Custom') }}</span>
                                        @elseif ($service['overridden'])
                                            <span>{{ __('Edited') }}</span>
                                        @endif
                                        @if (! $service['is_visible'])
                                            <span>{{ __('Hidden') }}</span>
                                        @endif
                                    </span>
                                </button>
                            @empty
                                <p class="px-3 py-4 text-[13px] text-neutral-500 dark:text-fg-dim">
                                    {{ __('No templates match.') }}
                                </p>
                            @endforelse
                        </div>
                    </div>

                    <div class="min-w-0">
                        @if ($creating)
                            <form wire:submit="create" class="flex flex-col gap-4">
                                <x-forms.input id="newName" label="{{ __('Template key') }}" required
                                    helper="{{ __('Lowercase slug used when launching, e.g. n8n or my-worker.') }}" />
                                <x-forms.input id="displayName" label="{{ __('Display name') }}" />
                                <x-forms.input id="category" label="{{ __('Category') }}" />
                                <x-forms.textarea id="description" label="{{ __('Description') }}" rows="2" />
                                <x-forms.checkbox id="isVisible" label="{{ __('Visible in the launch picker') }}" />
                                <x-forms.checkbox id="includesJupyter"
                                    label="{{ __('Includes Jupyter for file browsing') }}"
                                    helper="{{ __('When on, launch enables JupyterLab so members can browse mounted files. If the environment already has Jupyter, that container is reused.') }}" />
                                <x-forms.textarea id="compose" label="{{ __('Compose') }}" rows="18" monospace allowTab
                                    required
                                    helper="{{ __('Copied when someone launches this service.') }}" />
                                <div class="flex flex-wrap justify-end gap-2">
                                    <button type="button" wire:click="cancelCreate" class="button">
                                        {{ __('Cancel') }}
                                    </button>
                                    <button type="submit" class="button button-highlighted">
                                        {{ __('Create template') }}
                                    </button>
                                </div>
                            </form>
                        @elseif ($serviceName)
                            <form wire:submit="save" class="flex flex-col gap-4">
                                <x-forms.input id="displayName" label="{{ __('Display name') }}" />
                                <x-forms.input id="category" label="{{ __('Category') }}" />
                                <x-forms.textarea id="description" label="{{ __('Description') }}" rows="2" />
                                <x-forms.checkbox id="isVisible" label="{{ __('Visible in the launch picker') }}" />
                                <x-forms.checkbox id="includesJupyter"
                                    label="{{ __('Includes Jupyter for file browsing') }}"
                                    helper="{{ __('When on, launch enables JupyterLab so members can browse mounted files. If the environment already has Jupyter, that container is reused.') }}" />
                                <x-forms.textarea id="compose" :label="$serviceName" rows="18" monospace allowTab
                                    required
                                    helper="{{ __('This Compose is copied when a member creates the service. Redeploying an existing service does not pick up this change.') }}" />
                                <div class="flex flex-wrap justify-end gap-2">
                                    @if ($overridden)
                                        <button type="button" wire:click="restore" class="button">
                                            {{ $isCustom ? __('Delete custom template') : __('Restore catalog version') }}
                                        </button>
                                    @endif
                                    <button type="submit" class="button button-highlighted">
                                        {{ __('Save template') }}
                                    </button>
                                </div>
                            </form>
                        @else
                            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                                {{ __('Choose a template to edit, or create one for a custom image or stack.') }}
                            </p>
                        @endif
                    </div>
                </div>
            </x-application.settings-section>
        </div>
    </x-settings.layout>
</div>
