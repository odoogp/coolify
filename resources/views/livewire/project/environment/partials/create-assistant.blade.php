@php
    $createCategories = $createCategories ?? [];
    $createGroups = $createGroups ?? [];
    $createStep = $createStep ?? 'category';
    $selectedService = $selectedService ?? '';
    $selectedLabel = filled($selectedService)
        ? (\Illuminate\Support\Str::headline((string) $selectedService))
        : '';
@endphp

<div class="gc-create-assistant flex min-h-0 flex-col gap-4">
    <nav class="gc-create-steps" aria-label="{{ __('Create steps') }}">
        <ol class="gc-launch-steps-list">
            <li @class(['gc-launch-step', 'is-active' => $createStep === 'category'])>
                <span class="gc-launch-step-index">1</span>
                <span class="gc-launch-step-label">{{ __('Category') }}</span>
            </li>
            <li @class(['gc-launch-step', 'is-active' => $createStep === 'service'])>
                <span class="gc-launch-step-index">2</span>
                <span class="gc-launch-step-label">{{ __('Service') }}</span>
            </li>
            <li @class(['gc-launch-step', 'is-active' => $createStep === 'configure'])>
                <span class="gc-launch-step-index">3</span>
                <span class="gc-launch-step-label">{{ __('Connect') }}</span>
            </li>
            <li class="gc-launch-step">
                <span class="gc-launch-step-index">4</span>
                <span class="gc-launch-step-label">{{ __('Deploy') }}</span>
            </li>
        </ol>
    </nav>

    @if ($needsServer)
        <div class="rounded-lg border border-amber-200/80 bg-amber-50 px-3 py-2.5 text-[13px] text-amber-950 dark:border-amber-500/25 dark:bg-amber-500/10 dark:text-amber-100">
            <p class="mb-2">{{ __('Do you want to create a server?') }}</p>
            @if ($canAddServer)
                <a href="{{ route('server.create') }}" class="button button-highlighted" {{ wireNavigate() }}>
                    {{ __('Create a new server') }}
                </a>
            @else
                <p class="text-[12px] opacity-90">
                    {{ __('The owner has to add a server, or allow you to add servers, before you can create a project.') }}
                </p>
            @endif
        </div>
    @elseif ($createStep === 'category')
        <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
            {{ __('Pick a category first. Then choose the service, add it to the canvas, connect it, and Deploy.') }}
        </p>
        <div class="gc-create-assistant-grid min-h-0 flex-1 overflow-y-auto pr-1">
            @foreach ($createCategories as $category)
                <button type="button" class="gc-create-assistant-card"
                    wire:key="create-category-{{ $category['key'] }}"
                    wire:click="selectCreateCategory(@js($category['key']))">
                    <span class="gc-create-assistant-card-logo">
                        <x-reicon
                            :name="match ($category['key']) {
                                'agent' => 'feedback',
                                'github' => 'sources',
                                'stacks' => 'documentation',
                                default => 'layers',
                            }"
                            class="size-4" />
                    </span>
                    <span class="min-w-0 flex-1 text-left">
                        <span class="gc-create-assistant-card-title">{{ $category['label'] }}</span>
                        <span class="gc-create-assistant-card-desc">{{ $category['description'] }}</span>
                        @if (($category['count'] ?? 0) > 0 && $category['key'] !== 'agent')
                            <span class="mt-1 block text-[11px] text-neutral-500 dark:text-fg-dim">
                                {{ trans_choice(':count option|:count options', $category['count'], ['count' => $category['count']]) }}
                            </span>
                        @endif
                    </span>
                </button>
            @endforeach
        </div>
    @elseif ($createStep === 'service')
        <div class="flex flex-wrap items-center justify-between gap-2">
            <button type="button" class="button" wire:click="createAssistantBack">
                <x-reicon name="arrow-left" class="size-3.5" />
                {{ __('Back') }}
            </button>
            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ collect($createCategories)->firstWhere('key', $createCategory)['label'] ?? __('Services') }}
            </p>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium" for="createSearch">{{ __('Search the catalog') }}</label>
            <input id="createSearch" type="search" class="input"
                wire:model.live.debounce.200ms="createSearch"
                placeholder="{{ __('Filter this category…') }}"
                autocomplete="off" />
        </div>

        <div class="gc-create-assistant-groups min-h-0 flex-1 space-y-4 overflow-y-auto pr-1">
            @forelse ($createGroups as $group)
                <section class="gc-create-assistant-group" wire:key="create-group-{{ $group['key'] }}">
                    <div class="gc-create-assistant-grid">
                        @foreach ($group['items'] as $item)
                            @php $value = (string) ($item['value'] ?? ''); @endphp
                            <button type="button"
                                wire:key="create-item-{{ $group['key'] }}-{{ $value }}"
                                wire:click="selectCreateOption(@js($value))"
                                class="gc-create-assistant-card">
                                <span class="gc-create-assistant-card-logo">
                                    @if (filled($item['logo'] ?? null))
                                        <img src="{{ $item['logo'] }}" alt="" class="size-7 object-contain" loading="lazy" />
                                    @else
                                        <x-reicon name="layers" class="size-4" />
                                    @endif
                                </span>
                                <span class="min-w-0 flex-1 text-left">
                                    <span class="gc-create-assistant-card-title">{{ $item['label'] ?? $value }}</span>
                                    @if (filled($item['description'] ?? null))
                                        <span class="gc-create-assistant-card-desc">{{ \Illuminate\Support\Str::limit((string) $item['description'], 90) }}</span>
                                    @endif
                                </span>
                            </button>
                        @endforeach
                    </div>
                </section>
            @empty
                <p class="rounded-lg border border-dashed border-neutral-300 px-3 py-6 text-center text-[13px] text-neutral-500 dark:border-white/10 dark:text-fg-dim">
                    {{ __('No matching service') }}
                </p>
            @endforelse
        </div>
    @else
        <div class="flex flex-wrap items-center justify-between gap-2">
            <button type="button" class="button" wire:click="createAssistantBack">
                <x-reicon name="arrow-left" class="size-3.5" />
                {{ __('Back') }}
            </button>
            <p class="text-[13px] font-medium">{{ $selectedLabel }}</p>
        </div>

        @if ($projectServerLocked && filled($projectServerName))
            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ __('Runs on :server, the server already used by this project.', ['server' => $projectServerName]) }}
            </p>
        @elseif ($serverChoices !== [])
            <x-forms.listbox id="serverId" portal
                label="{{ $hasOtherServers ? __('Which server should run this?') : __('Do you want to create a new server?') }}"
                :options="$serverChoices" />
        @endif

        @include('livewire.project.partials.catalog-version-fields', [
            'selectedService' => $selectedService,
            'versionOptions' => $versionOptions,
            'needsVersionChoice' => $needsVersionChoice,
            'lockedOdooVersion' => $lockedOdooVersion,
        ])

        <div class="rounded-lg border border-neutral-200 bg-neutral-50 px-3 py-3 text-[13px] dark:border-white/[0.08] dark:bg-white/[0.03]">
            <p class="font-medium">{{ __('Next on the canvas') }}</p>
            <ol class="mt-2 list-decimal space-y-1 pl-4 text-neutral-600 dark:text-fg-dim">
                <li>{{ __('Add this service as a node.') }}</li>
                <li>{{ __('Drag connections to the nodes it should talk to.') }}</li>
                <li>{{ __('Click Deploy when you are ready to start containers.') }}</li>
            </ol>
        </div>

        <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
            <x-forms.button type="button" wire:click="closeCreateAssistant">
                {{ __('Cancel') }}
            </x-forms.button>
            <x-forms.button type="button" wire:click="addCatalogServiceToCanvas" wire:target="addCatalogServiceToCanvas" isHighlighted>
                {{ __('Add to canvas') }}
            </x-forms.button>
        </footer>
    @endif
</div>
