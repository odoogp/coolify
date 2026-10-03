<div>
    <x-slot:title>Appearance | Coolify</x-slot>
    <div x-data="{
        theme: localStorage.getItem('theme') === 'purple' ? 'custom' : (localStorage.getItem('theme') || 'light'),
        themeColor: localStorage.getItem('themeColor') || '#6b16ed',
        accents: {},
        pageWidth: localStorage.getItem('pageWidth') || 'full',
        init() {
            this.accents = window.readThemeAccents();
            localStorage.setItem('theme', this.theme);
            window.applyStoredTheme();
        },
        setTheme(type) {
            this.theme = type;
            localStorage.setItem('theme', type);
            window.applyStoredTheme();
        },
        previewAccent(color) {
            this.themeColor = color;
            this.accents = window.previewThemeAccent(color);
        },
        resetAccent(type) {
            this.accents = window.resetThemeAccent(type);
        },
        setWidth(width) {
            this.pageWidth = width;
            localStorage.setItem('pageWidth', width);
            window.dispatchEvent(new CustomEvent('page-width-changed', { detail: width }));
        },
    }" class="mt-8 flex w-full max-w-none flex-col gap-6 lg:mt-3">
        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>{{ __('Color theme') }}</h2>
                    <p>{{ __('Choose a theme. The accent color applies only to the theme you have selected.') }}</p>
                </div>
                <label class="flex items-center gap-3 text-sm text-black dark:text-fg">
                    <span class="relative size-8 shrink-0">
                        <span class="block size-8 rounded-full border border-black/10 dark:border-white/20"
                            :style="`background: ${themeColor}`"></span>
                        <input type="color" :value="themeColor" @input="previewAccent($event.target.value)"
                            @change="previewAccent($event.target.value)"
                            aria-label="{{ __('Accent color') }}"
                            class="absolute inset-0 z-10 cursor-pointer opacity-0" />
                    </span>
                    <span>
                        <span class="block font-semibold">{{ __('Accent color') }}</span>
                        <span class="block text-xs text-neutral-500 dark:text-fg-dim">{{ __('Use it on any theme. Reset a theme to restore its original design.') }}</span>
                    </span>
                </label>
            </div>
            <div class="application-settings-section-body grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    ['value' => 'light', 'label' => __('Letify Light'), 'description' => __('Original Letify light design.'), 'preview' => 'bg-[#f3eefe]'],
                    ['value' => 'system', 'label' => __('Match system'), 'description' => __('Follow your operating system.'), 'preview' => 'bg-gradient-to-r from-white via-neutral-400 to-[#050505]'],
                    ['value' => 'dark', 'label' => __('Midnight Dark'), 'description' => __('Dark surfaces and soft contrast.'), 'preview' => 'bg-[#181818]'],
                    ['value' => 'crystal', 'label' => __('Crystal Dark'), 'description' => __('Colored glass on a black canvas.'), 'preview' => 'bg-gradient-to-b from-[#ff7a62] via-[#7c5cff] to-[#07140c]'],
                    ['value' => 'crystal-light', 'label' => __('Crystal Light'), 'description' => __('The same glass in light tones.'), 'preview' => 'bg-gradient-to-b from-[#ffe4dc] via-[#efe8ff] to-white'],
                    ['value' => 'custom', 'label' => __('Custom Dark'), 'description' => __('Original dark accent.'), 'preview' => 'bg-[#2a1848]'],
                ] as $option)
                    <div role="button" tabindex="0"
                        @click="setTheme('{{ $option['value'] }}')"
                        @keydown.enter.prevent="setTheme('{{ $option['value'] }}')"
                        class="group relative overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.025] dark:hover:border-white/[0.12]"
                        :class="theme === '{{ $option['value'] }}'
                            ? 'ring-1 ring-coollabs/30 border-coollabs/40 dark:ring-warning/30 dark:border-warning/40'
                            : ''">
                        <div class="h-20 {{ $option['preview'] }} border-b border-neutral-200 dark:border-white/[0.07]"
                            :style="accents['{{ $option['value'] }}'] ? `background: color-mix(in srgb, ${themeColor} {{ in_array($option['value'], ['light', 'crystal-light'], true) ? '16%, white' : '28%, #101011' }})` : ''">
                            <div class="flex h-full items-center justify-center">
                                <div class="h-8 w-20 rounded-md border border-black/10 bg-white/80 shadow-sm dark:border-white/10 dark:bg-black/20"
                                    :style="accents['{{ $option['value'] }}'] ? `background: ${themeColor}` : ''"></div>
                            </div>
                        </div>
                        <div class="p-3">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-semibold text-black dark:text-fg">
                                    {{ $option['label'] }}
                                </span>
                                <span class="flex items-center gap-1">
                                    <button type="button" @click.stop="resetAccent('{{ $option['value'] }}')"
                                        :disabled="!accents['{{ $option['value'] }}']"
                                        aria-label="{{ __('Reset :theme', ['theme' => $option['label']]) }}"
                                        class="rounded px-1.5 py-0.5 text-[11px] text-neutral-500 enabled:hover:text-black disabled:opacity-30 dark:text-fg-faint dark:enabled:hover:text-fg">
                                        {{ __('Reset') }}
                                    </button>
                                    <x-reicon name="check-circle" class="size-4 text-coollabs dark:text-warning"
                                        x-show="theme === '{{ $option['value'] }}'" x-cloak />
                                </span>
                            </div>
                            <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                {{ $option['description'] }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="application-settings-section">
            <div class="application-settings-section-header">
                <div>
                    <h2>{{ __('Page width') }}</h2>
                    <p>{{ __('Choose how content uses the available browser width.') }}</p>
                </div>
            </div>
            <div class="application-settings-section-body grid gap-3 sm:grid-cols-2">
                @foreach ([
                    ['value' => 'full', 'label' => __('Full width'), 'description' => __('Use all available space for page content.')],
                    ['value' => 'centered', 'label' => __('Centered'), 'description' => __('Keep content centered at a comfortable maximum width.')],
                ] as $option)
                    <button type="button" @click="setWidth('{{ $option['value'] }}')"
                        class="group overflow-hidden rounded-[10px] border border-neutral-200 bg-white text-left transition-[border-color,box-shadow] hover:border-neutral-300 hover:shadow-sm dark:border-white/[0.07] dark:bg-white/[0.025] dark:hover:border-white/[0.12]"
                        :class="pageWidth === '{{ $option['value'] }}'
                            ? 'ring-1 ring-coollabs/30 border-coollabs/40 dark:ring-warning/30 dark:border-warning/40'
                            : ''">
                        <div class="flex h-20 items-center border-b border-neutral-200 bg-neutral-50 px-4 dark:border-white/[0.07] dark:bg-black/15">
                            <div class="flex h-11 w-full gap-1.5 rounded-md border border-neutral-300 bg-white p-1.5 dark:border-white/15 dark:bg-[#181818]">
                                <div class="w-3 shrink-0 rounded-sm bg-neutral-200 dark:bg-white/10"></div>
                                <div @class([
                                    'h-full rounded-sm bg-neutral-200 dark:bg-white/10',
                                    'w-full' => $option['value'] === 'full',
                                    'mx-auto w-2/3' => $option['value'] === 'centered',
                                ])></div>
                            </div>
                        </div>
                        <div class="p-3">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-semibold text-black dark:text-fg">{{ $option['label'] }}</span>
                                <x-reicon name="check-circle" class="size-4 text-coollabs dark:text-warning"
                                    x-show="pageWidth === '{{ $option['value'] }}'" x-cloak />
                            </div>
                            <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">{{ $option['description'] }}</p>
                        </div>
                    </button>
                @endforeach
            </div>
        </section>
    </div>
</div>
