@php
    $notificationMenuItems = [
        ['label' => __('Email'), 'route' => 'notifications.email', 'icon' => 'mail'],
        ['label' => __('Discord'), 'route' => 'notifications.discord', 'brandIcon' => 'discord'],
        ['label' => __('Telegram'), 'route' => 'notifications.telegram', 'brandIcon' => 'telegram'],
        ['label' => __('Slack'), 'route' => 'notifications.slack', 'brandIcon' => 'slack'],
        ['label' => __('Pushover'), 'route' => 'notifications.pushover', 'brandIcon' => 'pushover'],
        ['label' => __('Webhook'), 'route' => 'notifications.webhook', 'icon' => 'destinations'],
    ];
    if (isInstanceOwner()) {
        array_unshift($notificationMenuItems, ['label' => __('Notification center'), 'route' => 'notifications.center', 'icon' => 'notifications']);
    }
@endphp

<section class="application-settings-workspace w-full max-w-none">
    <header class="settings-mobile-header xl:hidden">
        <h1 class="settings-mobile-title">{{ __('Notifications') }}</h1>
        <p class="settings-mobile-description">{{ __('Configure how your team receives deployment and system alerts.') }}</p>
    </header>
    <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <aside class="application-settings-navigation min-w-0 xl:self-start">
            <nav aria-label="{{ __('Notification settings') }}"
                class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
                <div class="nav-section hidden xl:block">{{ __('Notifications') }}</div>
                @foreach ($notificationMenuItems as $menuItem)
                    <a wire:key="notification-settings-{{ str($menuItem['label'])->slug() }}"
                        @class(['menu-item', 'menu-item-active' => request()->routeIs($menuItem['route'])])
                        {{ wireNavigate() }} href="{{ route($menuItem['route']) }}">
                        @if (isset($menuItem['brandIcon']))
                            <span class="menu-item-icon bg-current"
                                style="mask: url('{{ asset('svgs/' . $menuItem['brandIcon'] . '.svg') }}') center / contain no-repeat; -webkit-mask: url('{{ asset('svgs/' . $menuItem['brandIcon'] . '.svg') }}') center / contain no-repeat;"></span>
                        @else
                            <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                        @endif
                        <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                    </a>
                @endforeach
            </nav>
        </aside>

        <div class="min-w-0">
            {{ $slot }}
        </div>
    </div>
</section>
