<nav class="app-sidebar flex flex-col flex-1 bg-white border-r border-neutral-200 dark:border-white/[0.06] dark:bg-panel pt-2"
    :class="collapsed ? 'px-2 lg:px-3 sidebar-collapsed' : 'px-2 lg:px-3'"
    @mouseover="
        if (!collapsed) return;
        const el = $event.target.closest('.menu-item, .menu-subitem');
        if (!el) { tooltip.show = false; return; }
        const text = el.getAttribute('title') || el.getAttribute('aria-label') || '';
        if (!text) return;
        const rect = el.getBoundingClientRect();
        tooltip.text = text;
        tooltip.x = rect.right + 8;
        tooltip.y = rect.top + rect.height / 2;
        tooltip.show = true;
    "
    @mouseleave="tooltip.show = false"
    x-data="{
        tooltip: { text: '', x: 0, y: 0, show: false },
        // macOS/iOS use ⌘; Windows/Linux use Ctrl+
        modKeyLabel: (() => {
            const platform = navigator.userAgentData?.platform || navigator.platform || '';
            const ua = navigator.userAgent || '';
            return /Mac|iPhone|iPad|iPod/i.test(platform) || /Mac OS X|Macintosh/i.test(ua) ? '⌘' : 'Ctrl+';
        })(),
        init() {
                window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
                    const userSettings = localStorage.getItem('theme');
                    if (userSettings !== 'system') { return; }
                    document.documentElement.classList.toggle('dark', e.matches);
                    document.documentElement.dataset.theme = e.matches ? 'dark' : 'light';
                });
                this.queryTheme();
            },
            queryTheme() {
                const darkModePreference = window.matchMedia('(prefers-color-scheme: dark)').matches;
                const userSettings = localStorage.getItem('theme') || 'dark';
                localStorage.setItem('theme', userSettings);
                let isDark = false;
                if (userSettings === 'dark' || userSettings === 'custom' || userSettings === 'crystal') {
                    document.documentElement.classList.add('dark');
                    isDark = true;
                } else if (userSettings === 'light' || userSettings === 'crystal-light') {
                    document.documentElement.classList.remove('dark');
                } else if (darkModePreference) {
                    document.documentElement.classList.add('dark');
                    isDark = true;
                } else {
                    document.documentElement.classList.remove('dark');
                }
                document.documentElement.dataset.theme = userSettings === 'custom' ? 'custom' : (userSettings === 'crystal' ? 'crystal' : (userSettings === 'crystal-light' ? 'crystal-light' : (isDark ? 'dark' : 'light')));
                document.querySelector('meta[name=theme-color]')?.setAttribute('content', isDark ? '#07080e' : '#f6f4f1');
            }
    }">
    {{-- Search is only useful when workspace resources are available --}}
    @if (isSubscribed() || ! isCloud())
        <div class="px-1 pb-3" :class="collapsed && 'lg:px-0 lg:flex lg:justify-center'">
            <button @click="$dispatch('open-global-search')" type="button"
                :title="@js(__('Search')) + ' (' + @js(__('Press / or')) + ' ' + modKeyLabel + 'K)'"
                class="menu-item justify-between !bg-neutral-100 dark:!bg-white/[0.04] hover:!bg-neutral-200 dark:hover:!bg-white/[0.07] !text-fg-faint"
                :class="collapsed && 'lg:w-8 lg:justify-center lg:px-0'">
                <span class="flex items-center gap-2.5 min-w-0">
                    <x-reicon name="search" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ __('Search') }}</span>
                </span>
                <kbd class="px-1.5 py-0.5 text-[11px] font-medium text-fg-faint bg-neutral-200 dark:bg-white/[0.06] rounded-md border border-transparent dark:border-white/5"
                    :class="collapsed && 'lg:hidden'" x-text="modKeyLabel + 'K'"></kbd>
            </button>
        </div>
    @endif

    <ul role="list" class="-mx-1 flex min-h-0 flex-1 flex-col gap-y-0.5 overflow-y-auto px-1 pb-2 scrollbar">
        @if (isSubscribed() || !isCloud())
            {{-- Workspace --}}
            <li class="nav-section" :class="collapsed && 'lg:hidden'">{{ __('Workspace') }}</li>
            <li>
                <a title="{{ __('Dashboard') }}" href="/" {{ wireNavigate() }}
                    class="{{ request()->is('/') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'">
                    <x-reicon name="dashboard" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ __('Dashboard') }}</span>
                </a>
            </li>
            <li>
                <a title="{{ __('Projects') }}" {{ wireNavigate() }}
                    class="{{ request()->is('project/*') || request()->is('projects') ? 'menu-item menu-item-active' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="/projects">
                    <x-reicon name="projects" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ __('Projects') }}</span>
                </a>
            </li>
            @if (auth()->user()?->isInstanceOwner() || auth()->user()?->isOwner())
                <li>
                    <a title="{{ __('Terminal') }}"
                        class="{{ request()->is('terminal*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                        :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('terminal') }}">
                        <x-reicon name="browser-terminal" class="menu-item-icon" />
                        <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ __('Terminal') }}</span>
                    </a>
                </li>
            @endif
            {{-- Infrastructure --}}
            <li class="nav-section mt-3" :class="collapsed && 'lg:hidden'">{{ __('Infrastructure') }}</li>
            <li>
                <a title="{{ __('Servers') }}" {{ wireNavigate() }}
                    class="{{ request()->is('server/*') || request()->is('servers') ? 'menu-item menu-item-active' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="/servers">
                    <x-reicon name="servers" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ __('Servers') }}</span>
                </a>
            </li>
            @if (isInstanceOwner())
                <li>
                    <a title="{{ __('Sources') }}" {{ wireNavigate() }}
                        class="{{ request()->is('source*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                        :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('source.all') }}">
                        <x-reicon name="sources" class="menu-item-icon" />
                        <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ __('Sources') }}</span>
                    </a>
                </li>
            @endif
            <li>
                <a title="{{ __('Destinations') }}" {{ wireNavigate() }}
                    class="{{ request()->is('destination*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('destination.index') }}">
                    <x-reicon name="destinations" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ __('Destinations') }}</span>
                </a>
            </li>
            <li>
                <a title="{{ __('S3 Storage') }}" {{ wireNavigate() }}
                    class="{{ request()->is('storages*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('storage.index') }}">
                    <x-reicon name="storages" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ __('S3 Storage') }}</span>
                </a>
            </li>
            <li>
                <a title="{{ __('Shared Variables') }}" {{ wireNavigate() }}
                    class="{{ request()->is('shared-variables*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('shared-variables.index') }}">
                    <x-reicon name="variables" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ __('Shared Variables') }}</span>
                </a>
            </li>

            {{-- Manage --}}
            <li class="nav-section mt-3" :class="collapsed && 'lg:hidden'">{{ __('Manage') }}</li>
            <li>
                <a title="{{ __('Team') }}" {{ wireNavigate() }}
                    class="{{ request()->is('team*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('team.index') }}">
                    <x-reicon name="teams" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ __('Team') }}</span>
                </a>
            </li>
            <li>
                <a title="{{ __('Notifications') }}" {{ wireNavigate() }}
                    class="{{ request()->is('notifications*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('notifications.email') }}">
                    <x-reicon name="notifications" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ __('Notifications') }}</span>
                </a>
            </li>

            <li>
                <a title="{{ __('Keys & Tokens') }}" {{ wireNavigate() }}
                    class="{{ request()->is('security*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('security.private-key.index') }}">
                    <x-reicon name="keys" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ __('Keys & Tokens') }}</span>
                </a>
            </li>
            @if (isCloud() && auth()->user()->isAdmin())
                <li>
                    <a title="{{ __('Subscription') }}" {{ wireNavigate() }}
                        class="{{ request()->is('subscription*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                        :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('subscription.show') }}">
                        <x-reicon name="subscription" class="menu-item-icon" />
                        <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ __('Subscription') }}</span>
                    </a>
                </li>
            @endif
            <li>
                <a title="{{ __('Tags') }}" {{ wireNavigate() }}
                    class="{{ request()->is('tags*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('tags.show') }}">
                    <x-reicon name="tags" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ __('Tags') }}</span>
                </a>
            </li>
            @if (isInstanceAdmin())
                <li>
                    <a title="{{ __('Settings') }}" {{ wireNavigate() }}
                        class="{{ request()->is('settings*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                        :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('settings.index') }}">
                        <x-reicon name="settings" class="menu-item-icon" />
                        <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ __('Settings') }}</span>
                    </a>
                </li>
            @endif
            <li class="flex-1" aria-hidden="true"></li>
        @endif
        @if (auth()->id() === 0 && (isCloud() || isDev()))
            <li>
                <a title="{{ __('Admin') }}" {{ wireNavigate() }}
                    class="{{ request()->is('admin') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'" href="{{ route('admin.index') }}">
                    <x-reicon name="fire" class="menu-item-icon text-pink-500" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ __('Admin') }}</span>
                </a>
            </li>
        @endif
        @if (isCloud() && ! isSubscribed())
            {{-- Unsubscribed cloud has no workspace items — keep these at the top of the list. --}}
            <li class="nav-section" :class="collapsed && 'lg:hidden'">{{ __('Account') }}</li>
            <li>
                <a title="{{ __('Subscription') }}" {{ wireNavigate() }}
                    class="{{ request()->is('subscription*') ? 'menu-item-active menu-item' : 'menu-item' }}"
                    :class="collapsed && 'lg:justify-center lg:px-0'"
                    href="{{ isSubscriptionOnGracePeriod() ? route('subscription.show') : route('subscription.index') }}">
                    <x-reicon name="subscription" class="menu-item-icon" />
                    <span class="menu-item-label" :class="collapsed && 'lg:hidden'">{{ __('Subscription') }}</span>
                </a>
            </li>
            @if (auth()->user()->teams()->get()->count() > 1)
                <li class="mt-2">
                    <livewire:navbar-delete-team />
                </li>
            @endif
        @endif
    </ul>
    {{-- Sticky sidebar collapser (desktop only; mobile uses a temporary slide-over) --}}
    <div class="sticky bottom-0 mt-auto -mx-2 hidden items-center gap-1 bg-white px-2 py-2 dark:bg-panel lg:-mx-3 lg:flex lg:px-3"
        :class="collapsed ? 'flex-col-reverse justify-center' : 'justify-between'">
        <x-top-user-menu sidebar />
        <button type="button" @click="toggleSidebar()" title="{{ __('Toggle sidebar') }}" aria-label="{{ __('Toggle sidebar') }}"
            class="menu-item w-8 shrink-0 justify-center px-0">
            <svg class="menu-item-icon" viewBox="0 0 24 24" fill="none">
                <rect x="3" y="4" width="18" height="16" rx="2" stroke="currentColor" stroke-width="1.6" />
                <path d="M9 4v16" stroke="currentColor" stroke-width="1.6" />
            </svg>
        </button>
    </div>
    <div x-show="collapsed && tooltip.show" x-cloak x-transition.opacity.duration.150ms
        :style="`left: ${tooltip.x}px; top: ${tooltip.y}px;`"
        class="app-sidebar-tip fixed z-[10000] max-lg:hidden -translate-y-1/2 px-2 py-1 text-[11px] font-medium leading-none rounded-md whitespace-nowrap pointer-events-none shadow-lg border bg-neutral-900 text-white border-neutral-700 dark:bg-white dark:text-neutral-900 dark:border-neutral-200"
        x-text="tooltip.text"></div>
</nav>
