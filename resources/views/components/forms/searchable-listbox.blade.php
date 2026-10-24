@props([
    'id' => null,
    'label' => null,
    'helper' => null,
    'required' => false,
    'options' => [], // list of ['value' => ..., 'label' => ..., 'disabled' => bool, 'description' => ?, 'logo' => ?, 'category' => ?]
    'placeholder' => __('Select…'),
    'searchPlaceholder' => 'Search…',
    'emptyText' => 'No matching options',
    'live' => false,
    'onChange' => null, // optional $wire method to call after a selection
    'wire' => true, // false = purely client-side value (no Livewire binding)
    'value' => null, // initial value when wire=false
    'disabled' => false,
    'portal' => false,
    // Keep Alpine state across Livewire morphs (catalog pickers with live + modal).
    'ignoreMorph' => false,
])

@php
    $hasRichOptions = collect($options)->contains(
        fn (mixed $option): bool => is_array($option)
            && (filled($option['logo'] ?? null) || filled($option['description'] ?? null))
    );
@endphp

<div class="w-full min-w-0">
    @if ($label)
        {{--
            Keep helper outside the label so taps do not open the listbox trigger.
            Margin lives only on this wrapper (mb-0 on the label) so spacing matches
            plain text labels and is not doubled by .application-settings-form label rules.
        --}}
        {{-- Fixed h-4 matches the helper icon so side-by-side fields align with or without a helper. --}}
        <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
            <label for="{{ $id }}-trigger" class="mb-0! flex items-center gap-1.5 leading-4">
                {{ $label }}
                @if ($required)
                    <x-highlighted text="*" />
                @endif
            </label>
            @if ($helper)
                <x-helper :helper="$helper" />
            @endif
        </div>
    @endif
    <div class="relative min-w-0" x-data="{
        open: false,
        query: '',
        saving: false,
        positioned: false,
        options: @js(array_values($options)),
        value: @if (!$wire)
            @js($value)
        @elseif ($live && ! $onChange)
            @entangle($id).live
        @else
            @entangle($id)
        @endif,
        get selected() {
            return this.options.find((option) => String(option.value) === String(this.value)) ?? null;
        },
        get current() {
            const found = this.selected;
            if (! found) {
                return @js($placeholder);
            }
            const category = found.category ? ` · ${found.category}` : '';

            return `${found.label ?? ''}${category}`;
        },
        get filtered() {
            const query = this.query.toLowerCase().trim();
            if (query === '') {
                return this.options;
            }

            return this.options.filter((option) => {
                const haystack = [
                    option.label,
                    option.value,
                    option.category,
                    option.description,
                ].map((part) => String(part ?? '').toLowerCase()).join(' ');

                return haystack.includes(query);
            });
        },
        portalHost() {
            if (! @js((bool) $portal)) {
                return null;
            }

            const trigger = this.$refs.trigger;
            if (! trigger) {
                return document.body;
            }

            // Prefer the nearest focus trap / modal shell so x-trap.inert does not
            // mark the search input as inert (body teleports sit outside the trap).
            // Alpine traps use modifiers (x-trap.inert.noscroll), so a bare [x-trap]
            // selector never matches — walk attributes instead.
            const explicit = trigger.closest('[data-listbox-portal-root]');
            if (explicit) {
                return explicit;
            }

            let node = trigger.parentElement;
            while (node && node !== document.documentElement) {
                if ([...node.attributes].some((attr) => attr.name === 'x-trap' || attr.name.startsWith('x-trap.'))) {
                    return node;
                }
                node = node.parentElement;
            }

            return document.body;
        },
        toggle() {
            if (@js((bool) $disabled)) {
                return;
            }

            this.open = !this.open;
            this.positioned = false;
            if (this.open) {
                this.$nextTick(() => {
                    requestAnimationFrame(() => {
                        this.positionPanel();
                        this.$refs.search?.focus({ preventScroll: true });
                    });
                });
            } else {
                this.query = '';
            }
        },
        portalSurface(host) {
            if (! host || host === document.body) {
                return host;
            }

            return host.querySelector('[data-listbox-portal-surface]') ?? host;
        },
        positionPanel(panel = null) {
            const trigger = this.$refs.trigger;
            panel ??= this.$refs.panel;
            if (!trigger || !panel) {
                return;
            }

            const host = this.portalHost();
            const surface = this.portalSurface(host);
            if (surface && panel.parentElement !== surface) {
                surface.appendChild(panel);
            }

            if (@js((bool) $portal)) {
                const gap = 4;
                const edge = 12;
                const triggerRect = trigger.getBoundingClientRect();
                const panelHeight = Math.min(panel.scrollHeight, 288);
                const width = `${Math.round(triggerRect.width)}px`;
                // Override .listbox-panel { min-width: max(100%, …) } which stretches
                // to the modal width when the panel is reparented into the trap shell.
                panel.style.setProperty('min-width', width, 'important');
                panel.style.setProperty('width', width, 'important');
                panel.style.setProperty('max-width', width, 'important');
                panel.style.right = 'auto';
                panel.style.flex = 'none';
                panel.style.pointerEvents = 'auto';
                panel.style.zIndex = '9999';

                if (surface && surface !== document.body) {
                    panel.style.position = 'absolute';
                    const surfaceRect = surface.getBoundingClientRect();
                    const fitsBelow = surfaceRect.bottom - triggerRect.bottom - gap >= Math.min(panelHeight, 160)
                        || window.innerHeight - triggerRect.bottom - gap >= panelHeight;
                    const top = fitsBelow
                        ? triggerRect.bottom - surfaceRect.top + gap
                        : Math.max(0, triggerRect.top - surfaceRect.top - gap - panelHeight);
                    const left = triggerRect.left - surfaceRect.left;

                    panel.style.top = `${top}px`;
                    panel.style.left = `${left}px`;
                } else {
                    panel.style.position = 'fixed';
                    const fitsBelow = window.innerHeight - triggerRect.bottom - gap >= panelHeight;
                    const top = fitsBelow
                        ? triggerRect.bottom + gap
                        : Math.max(edge, triggerRect.top - gap - panelHeight);
                    const left = Math.min(
                        Math.max(edge, triggerRect.left),
                        window.innerWidth - triggerRect.width - edge,
                    );

                    panel.style.top = `${top}px`;
                    panel.style.left = `${left}px`;
                }
            }

            this.positioned = true;
        },
        close() {
            this.open = false;
            this.query = '';
            this.positioned = false;
        },
        isInsidePanel(target) {
            return Boolean(target?.closest?.('[data-searchable-listbox-panel]'));
        },
        async choose(option) {
            if (this.saving || option?.disabled) {
                return;
            }

            const next = option?.value;
            // Set value before close so live entangle commits even if morph races the panel.
            if (String(next) !== String(this.value)) {
                this.value = next;
            }
            this.close();
            @if ($onChange)
                this.saving = true;
                try {
                    await this.$wire.{{ $onChange }}();
                } finally {
                    this.saving = false;
                }
            @endif
        }
    }" x-modelable="value" :class="{ 'pointer-events-none opacity-70': saving }"
        @if ($ignoreMorph) wire:ignore @endif
        {{ $attributes->whereStartsWith('x-model') }}
        {{ $attributes->whereStartsWith('x-effect') }}
        @mousedown.outside="if (!isInsidePanel($event.target)) close()"
        @keydown.escape.window="open && close()"
        x-effect="if (!open && $refs.panel && @js((bool) $portal)) $refs.panel.style.visibility = 'hidden'">
        <button x-ref="trigger" id="{{ $id }}-trigger" type="button" class="listbox-trigger" @click="toggle()"
            @disabled($disabled)
            {{ $attributes->whereStartsWith('x-bind:disabled') }} aria-haspopup="listbox"
            :aria-expanded="open" :title="current">
            <span class="listbox-trigger-label flex min-w-0 items-center gap-2">
                <template x-if="selected?.logo">
                    <img :src="selected.logo" alt="" class="size-5 shrink-0 rounded object-contain"
                        x-on:error="$el.style.display='none'" />
                </template>
                <span class="truncate" x-text="current"></span>
            </span>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                stroke="currentColor" class="size-3.5 shrink-0 opacity-60">
                <path stroke-linecap="round" stroke-linejoin="round" d="m8 9 4-4 4 4m0 6-4 4-4-4" />
            </svg>
        </button>
        @if ($hasRichOptions)
            <div x-show="selected && (selected.description || selected.logo)" x-cloak
                class="searchable-listbox-preview mt-2">
                <div class="flex min-w-0 items-start gap-3">
                    <div
                        class="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-neutral-200 bg-neutral-50 dark:border-white/[0.08] dark:bg-white/[0.04]">
                        <template x-if="selected?.logo">
                            <img :src="selected.logo" alt="" class="size-full object-contain p-2"
                                x-on:error="$el.src=@js(asset('svgs/default.webp'))" />
                        </template>
                        <template x-if="!selected?.logo">
                            <span class="text-[11px] font-medium text-neutral-400 dark:text-fg-faint"
                                x-text="(selected?.label || '?').slice(0, 1)"></span>
                        </template>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                            <p class="truncate text-[13px] font-semibold text-black dark:text-fg"
                                x-text="selected?.label"></p>
                            <p x-show="selected?.category"
                                class="truncate text-[11px] text-neutral-500 dark:text-fg-faint"
                                x-text="selected?.category"></p>
                        </div>
                        <p x-show="selected?.description"
                            class="mt-1 line-clamp-2 text-[12px] leading-5 text-neutral-600 dark:text-fg-dim"
                            x-text="selected?.description"></p>
                    </div>
                </div>
            </div>
        @endif
        <div x-ref="panel" data-searchable-listbox-panel
            @if ($portal)
                style="position: absolute; z-index: 9999; visibility: hidden; pointer-events: auto"
                :style="{ zIndex: 9999, visibility: open && positioned ? 'visible' : 'hidden', pointerEvents: open ? 'auto' : 'none' }"
                x-effect="if (open) requestAnimationFrame(() => positionPanel($el))"
                @resize.window="open && positionPanel($el)"
                @scroll.window.capture="open && positionPanel($el)"
            @endif
            class="listbox-panel searchable-listbox-panel pointer-events-auto" x-show="open" x-cloak role="listbox"
            @mousedown.stop @click.stop>
            <div class="searchable-listbox-search">
                <x-reicon name="search"
                    class="pointer-events-none absolute top-1/2 left-3 size-3 -translate-y-1/2 text-neutral-400 dark:text-fg-faint" />
                <input x-ref="search" type="search" x-model="query" autocomplete="off"
                    placeholder="{{ $searchPlaceholder }}"
                    class="searchable-listbox-search-input"
                    @keydown.enter.prevent="filtered.length === 1 && choose(filtered[0])"
                    @keydown.escape.stop="close()" />
            </div>
            <div class="searchable-listbox-options">
                <template x-for="option in filtered" :key="String(option.value)">
                    {{-- mousedown: select before outside/trap handlers can steal the click --}}
                    <button type="button" class="listbox-option searchable-listbox-option" role="option"
                        :class="{ 'listbox-option-disabled': option.disabled }"
                        :aria-selected="String(option.value) === String(value)"
                        @mousedown.prevent="choose(option)">
                        <span class="flex min-w-0 flex-1 items-start gap-2.5">
                            <template x-if="option.logo">
                                <img :src="option.logo" alt=""
                                    class="mt-0.5 size-7 shrink-0 rounded-md object-contain"
                                    x-on:error="$el.src=@js(asset('svgs/default.webp'))" />
                            </template>
                            <span class="min-w-0 flex-1">
                                <span class="flex min-w-0 items-baseline gap-1.5">
                                    <span class="truncate font-medium" x-text="option.label"></span>
                                    <span x-show="option.category"
                                        class="truncate text-[11px] font-normal text-neutral-500 dark:text-fg-faint"
                                        x-text="option.category"></span>
                                </span>
                                <span x-show="option.description"
                                    class="mt-0.5 line-clamp-2 text-[11px] leading-4 font-normal whitespace-normal text-neutral-500 dark:text-fg-dim"
                                    x-text="option.description"></span>
                            </span>
                        </span>
                        <svg x-show="String(option.value) === String(value)" xmlns="http://www.w3.org/2000/svg"
                            fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"
                            class="mt-1 size-3.5 shrink-0">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
                        </svg>
                    </button>
                </template>
                <p x-show="filtered.length === 0" class="searchable-listbox-empty">
                    {{ $emptyText }}
                </p>
            </div>
        </div>
    </div>
</div>
