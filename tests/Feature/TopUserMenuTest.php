<?php

it('renders the same compact profile trigger on all breakpoints', function () {
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));

    expect($menu)
        ->toContain('{{ $userInitial }}')
        ->toContain('aria-label="Account menu for {{ $userName }}"')
        ->not->toContain('sm:hidden')
        ->not->toContain('hidden sm:block max-w-[9rem]')
        ->not->toContain('sm:px-3');
});

it('still shows the user name and email inside the dropdown panel', function () {
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));

    expect($menu)
        ->toContain('truncate text-[13px] font-semibold text-black dark:text-fg">{{ $userName }}</div>')
        ->toContain('{{ $userEmail }}');
});

it('animates the dropdown panel when the user menu opens', function () {
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));
    $stylesheet = file_get_contents(resource_path('css/app.css'));

    expect($menu)
        ->toContain('animate-in fade-in zoom-in-95 duration-150')
        ->toContain("'origin-bottom-left' => \$sidebar")
        ->toContain("'origin-top-right' => ! \$sidebar");

    expect($stylesheet)->toContain('@import "tw-animate-css";');
});

it('sizes the account menu like the notice panel on mobile and desktop', function () {
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));
    $stylesheet = file_get_contents(resource_path('css/app.css'));

    expect($menu)
        ->toContain("'is-viewport-panel' => ! \$sidebar")
        ->toContain("'bottom-full! left-0! right-auto! top-auto! mb-1! w-60! max-h-none! min-w-0! overflow-visible!' => \$sidebar")
        ->not->toContain("'right-0! left-auto!' => ! \$sidebar");

    expect($stylesheet)
        ->toContain('.notice-panel,'."\n".'.top-user-menu-panel.is-viewport-panel {')
        ->toContain('.listbox-panel.top-user-menu-panel.is-viewport-panel')
        ->toContain('right: 0.75rem !important;')
        ->toContain('left: 0.75rem !important;')
        ->toContain('width: auto !important;')
        ->toContain('width: 20rem !important;')
        ->toContain('max-height: calc(100dvh - 4.5rem) !important;')
        ->toContain('overflow-y: auto !important;')
        ->toContain('flex-shrink: 0;');
});

it('changes appearance from a submenu instead of navigating to a separate page', function () {
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));

    expect($menu)
        ->toContain('appearanceOpen: false')
        ->toContain('@click.outside="open = false; appearanceOpen = false"')
        ->toContain("theme: localStorage.getItem('theme') === 'purple' ? 'custom' : (localStorage.getItem('theme') || 'light')")
        ->toContain('setTheme(type, closeMenu = true)')
        ->toContain("this.setTheme('custom', false)")
        ->not->toContain('@change="appearanceOpen = false; open = false"')
        ->toContain('this.appearanceOpen = false;')
        ->toContain('this.open = false;')
        ->toContain('<div x-show="open" x-cloak @class([')
        ->toContain('<div x-show="appearanceOpen" x-cloak class="mx-1 grid shrink-0 gap-0.5 pb-1 pl-6">')
        ->not->toContain('x-collapse');
        ->not->toContain('<template x-if="open">')
        ->not->toContain('x-show.important="open"')
        ->not->toContain('<div x-show="open" x-cloak x-transition.opacity.duration.120ms')
        ->toContain("['value' => 'light', 'label' => 'Light'")
        ->toContain("['value' => 'system', 'label' => 'System'")
        ->toContain("['value' => 'dark', 'label' => 'Dark'")
        ->toContain('hover:bg-neutral-200 hover:text-neutral-950')
        ->not->toContain("route('profile.appearance')");
});

it('offers page width controls inside the appearance menu', function () {
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));

    expect($menu)
        ->toContain("pageWidth: localStorage.getItem('pageWidth') || 'full'")
        ->toContain("['value' => 'full', 'label' => 'Full width']")
        ->toContain("['value' => 'centered', 'label' => 'Centered']")
        ->toContain("@click=\"setWidth('{{ \$option['value'] }}')\"")
        ->toContain('Full width')
        ->toContain('Centered')
        ->toContain("localStorage.setItem('pageWidth', width)")
        ->toContain("new CustomEvent('page-width-changed', { detail: width })");
});

it('adjusts font size from inside the appearance menu without showing a number', function () {
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));
    $styles = file_get_contents(resource_path('css/app.css'));
    $layout = file_get_contents(resource_path('views/layouts/base.blade.php'));
    $appearance = substr($menu, strpos($menu, 'x-show="appearanceOpen"'));

    expect($appearance)
        ->toContain("{{ __('Page width') }}")
        ->toContain("{{ __('Font size') }}")
        ->toContain('class="font-size-range"')
        ->toContain('min="-2" max="4" step="1"')
        ->toContain('setFontDelta($event.target.value)')
        ->not->toContain('x-text="fontDelta"');

    expect(strpos($appearance, "{{ __('Page width') }}"))
        ->toBeLessThan(strpos($appearance, "{{ __('Font size') }}"));

    expect($styles)
        ->toContain('--font-delta: 0')
        ->toContain('--type-title: calc(24px + (var(--font-delta) * 1px))')
        ->toContain('--type-subtitle: calc(14px + (var(--font-delta) * 1px))')
        ->toContain('--type-body: calc(13px + (var(--font-delta) * 1px))')
        ->toContain('--type-ui: calc(12px + (var(--font-delta) * 1px))')
        ->toContain('--type-meta: calc(11px + (var(--font-delta) * 1px))')
        ->toContain('--type-kicker: calc(10px + (var(--font-delta) * 1px))')
        ->toContain('.menu-subitem,')
        ->toContain('.sub-menu-item,')
        ->toContain('.nav-section {')
        ->toContain('.dropdown-item,');

    expect($layout)->toContain('window.applyFontDelta');
});
