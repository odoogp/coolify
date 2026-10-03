@props([
    'sidebar' => false,
])

@php
    $user = auth()->user();
    $userName = $user?->name ?? __('Account');
    $userEmail = $user?->email ?? '';
    $userInitial = strtoupper(mb_substr($user?->name ?: ($user?->email ?: 'A'), 0, 1));
@endphp
<div @class(['relative', 'min-w-0' => $sidebar]) x-data="{
    open: false,
    appearanceOpen: false,
    theme: localStorage.getItem('theme') === 'purple' ? 'custom' : (localStorage.getItem('theme') || 'dark'),
    pageWidth: localStorage.getItem('pageWidth') || 'full',
    themeColor: localStorage.getItem('themeColor') || '#6b16ed',
    accents: {},
    avatarUrl: @js($user?->avatar_path ? route('profile.avatar', ['v' => $user->updated_at->timestamp]) : null),
    init() {
        this.accents = window.readThemeAccents();
    },
    openPanel() {
        this.appearanceOpen = false;
        this.open = true;
    },
    closePanel() {
        this.open = false;
    },
    setTheme(type, closeMenu = true) {
        this.theme = type;
        localStorage.setItem('theme', type);

        if (closeMenu) {
            this.closePanel();
        }

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
}" @avatar-updated.window="avatarUrl = $event.detail.url" @keydown.escape.window="closePanel()"
    @click.outside="closePanel()">
    <button type="button" @click="open ? closePanel() : openPanel()"
        title="{{ $userName }}" aria-label="{{ __('Account menu for :name', ['name' => $userName]) }}"
        @if ($sidebar) :class="collapsed && 'w-8 justify-center px-0'" @endif
        @class([
            'flex h-8 items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-100 px-2 shadow-sm transition-colors hover:bg-neutral-200 dark:border-white/[0.08] dark:bg-white/[0.06] dark:hover:bg-white/[0.1]',
            'max-w-36' => $sidebar,
        ])>
        <img x-cloak x-show="avatarUrl" :src="avatarUrl" alt="{{ $userName }}"
            class="size-5 shrink-0 rounded-full object-cover">
        <span x-show="!avatarUrl"
            class="flex size-5 shrink-0 items-center justify-center rounded-full bg-neutral-200 text-[11px] font-semibold text-neutral-700 dark:bg-white/[0.1] dark:text-fg">
            {{ $userInitial }}
        </span>
        @if ($sidebar)
            <span class="min-w-0 truncate text-xs font-medium" :class="collapsed && 'hidden'">{{ $userName }}</span>
        @endif
        <svg class="size-3.5 shrink-0 text-neutral-400 dark:text-fg-faint transition-transform"
            :class="[open && 'rotate-180', {{ $sidebar ? "collapsed && 'hidden'" : 'false' }}]"
            viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </button>

    <div x-show="open" x-cloak @class([
            'top-user-menu-panel listbox-panel z-[90]! max-h-none! w-60! min-w-0! overflow-visible! animate-in fade-in zoom-in-95 duration-150',
            'right-0! left-auto!' => ! $sidebar,
            'bottom-full! left-0! right-auto! top-auto! mb-1!' => $sidebar,
            'origin-bottom-left' => $sidebar,
            'origin-top-right' => ! $sidebar,
        ])>
        <div class="min-w-0 px-2 py-1.5">
            <div class="truncate text-[13px] font-semibold text-black dark:text-fg">{{ $userName }}</div>
            <div class="truncate text-[11px] text-neutral-500 dark:text-fg-faint">{{ $userEmail }}</div>
        </div>
        <div class="my-1 h-px bg-neutral-200 dark:bg-white/[0.07]"></div>

        <a href="{{ route('profile') }}" {{ wireNavigate() }} class="listbox-option">
            <span class="flex items-center gap-2">
                <x-reicon name="profile" class="size-4 opacity-80" />
                {{ __('Profile') }}
            </span>
        </a>
        <button type="button" class="listbox-option w-full" @click="appearanceOpen = !appearanceOpen"
            :aria-expanded="appearanceOpen">
            <span class="flex items-center gap-2">
                <x-reicon name="settings" class="size-4 opacity-80" />
                {{ __('Appearance') }}
            </span>
            <svg class="size-3.5 text-neutral-400 transition-transform dark:text-fg-faint"
                :class="appearanceOpen && 'rotate-180'" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" />
            </svg>
        </button>
        <div x-show="appearanceOpen" x-collapse class="mx-1 grid gap-0.5 pb-1 pl-6">
            <div class="flex h-8 w-full items-center gap-2 rounded-md px-2 text-xs text-neutral-600 dark:text-fg-dim">
                <span class="relative size-3.5 shrink-0">
                    <span class="block size-3.5 rounded-full border border-black/10 dark:border-white/20"
                        :style="`background: ${themeColor}`"></span>
                    <input type="color" :value="themeColor"
                        @input="previewAccent($event.target.value)"
                        @change="previewAccent($event.target.value)"
                        aria-label="{{ __('Accent color') }}"
                        class="absolute -inset-1 z-10 cursor-pointer opacity-0" />
                </span>
                <span class="min-w-0 flex-1 truncate">{{ __('Accent color') }}</span>
            </div>
            @foreach ([
                ['value' => 'light', 'label' => __('Letify Light')],
                ['value' => 'system', 'label' => __('Match system')],
                ['value' => 'dark', 'label' => __('Midnight Dark')],
                ['value' => 'crystal', 'label' => __('Crystal Dark')],
                ['value' => 'crystal-light', 'label' => __('Crystal Light')],
                ['value' => 'custom', 'label' => __('Custom Dark')],
            ] as $option)
                <div class="flex h-8 w-full items-center gap-1 rounded-md px-2 text-left text-xs text-neutral-600 transition-colors hover:bg-neutral-200 hover:text-neutral-950 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
                    <button type="button" @click="setTheme('{{ $option['value'] }}')"
                        class="min-w-0 flex-1 truncate text-left">
                        {{ $option['label'] }}
                    </button>
                    <button type="button" @click.stop="resetAccent('{{ $option['value'] }}')"
                        :disabled="!accents['{{ $option['value'] }}']"
                        aria-label="{{ __('Reset :theme', ['theme' => $option['label']]) }}"
                        class="shrink-0 rounded p-0.5 text-neutral-400 enabled:hover:text-neutral-950 disabled:opacity-25 dark:text-fg-faint dark:enabled:hover:text-fg">
                        <svg class="size-3.5" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                            <path d="M2.2 6a3.8 3.8 0 1 0 1-2.5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" />
                            <path d="M2 1.8v2.2h2.2" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                    <svg x-show="theme === '{{ $option['value'] }}'" class="size-3.5 shrink-0 text-coollabs dark:text-warning"
                        viewBox="0 0 12 12" fill="none" aria-hidden="true">
                        <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor" stroke-width="1.4"
                            stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
            @endforeach
            <div class="my-1 h-px bg-neutral-200 dark:bg-white/[0.07]"></div>
            <div class="px-2 pt-1 pb-0.5 text-[10px] font-medium tracking-wide text-neutral-400 uppercase dark:text-fg-faint">
                {{ __('Page width') }}
            </div>
            @foreach ([
                ['value' => 'full', 'label' => __('Full width')],
                ['value' => 'centered', 'label' => __('Centered')],
            ] as $option)
                <button type="button" @click="setWidth('{{ $option['value'] }}')"
                    class="flex h-8 w-full items-center justify-between rounded-md px-2 text-left text-xs text-neutral-600 transition-colors hover:bg-neutral-200 hover:text-neutral-950 dark:text-fg-dim dark:hover:bg-white/[0.06] dark:hover:text-fg">
                    <span>{{ $option['label'] }}</span>
                    <svg x-show="pageWidth === '{{ $option['value'] }}'"
                        class="size-3.5 text-coollabs dark:text-warning" viewBox="0 0 12 12" fill="none"
                        aria-hidden="true">
                        <path d="m2.5 6.25 2.1 2.1 4.9-5" stroke="currentColor" stroke-width="1.4"
                            stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>
            @endforeach
        </div>

        <div class="my-1 h-px bg-neutral-200 dark:bg-white/[0.07]"></div>
        <div class="mx-1 grid gap-0.5 pb-1">
            <x-locale-switcher />
        </div>

        <div class="my-1 h-px bg-neutral-200 dark:bg-white/[0.07]"></div>

        <livewire:settings-dropdown trigger="account-menu" />
        <a href="https://coolify.io/docs" target="_blank" rel="noopener noreferrer" class="listbox-option">
            <span class="flex items-center gap-2">
                <x-reicon name="documentation" class="size-4 opacity-80" />
                {{ __('Documentation') }}
            </span>
        </a>
        <x-modal-input title="{{ __('How can we help?') }}">
            <x-slot:content>
                <div class="listbox-option cursor-pointer" @click="closePanel()">
                    <span class="flex items-center gap-2">
                        <x-reicon name="feedback" class="size-4 opacity-80" />
                        {{ __('Feedback') }}
                    </span>
                </div>
            </x-slot:content>
            <livewire:help />
        </x-modal-input>
        <div class="my-1 h-px bg-neutral-200 dark:bg-white/[0.07]"></div>

        <form action="/logout" method="POST">
            @csrf
            <button type="submit" class="listbox-option w-full text-left text-error dark:text-error">
                <span class="flex items-center gap-2">
                    <x-reicon name="logout" class="size-4 opacity-90" />
                    {{ __('Log out') }}
                </span>
            </button>
        </form>
    </div>
</div>
