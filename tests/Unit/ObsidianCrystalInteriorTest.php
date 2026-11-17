<?php

it('retargets crystal themes to Obsidian Crystal tokens for dark and light', function () {
    $styles = file_get_contents(resource_path('css/app.css'));
    $appearance = file_get_contents(resource_path('views/livewire/profile/appearance.blade.php'));
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));

    expect($styles)
        ->toContain('/* Obsidian Crystal')
        ->toContain('html[data-theme="crystal"]')
        ->toContain('html[data-theme="crystal-light"]')
        ->toContain('--color-panel: #070b14;')
        ->toContain('--color-panel: #f4f6fb;')
        ->toContain('--obsidian-glass:')
        ->toContain('.obsidian-search-trigger')
        ->toContain('.command-palette-home')
        ->not->toContain('radial-gradient(90% 75% at var(--spot-x) var(--spot-y), #ff9a7a 0%, rgb(226 85 60 / 0.28) 42%, transparent 68%)')
        ->and($appearance)
        ->toContain("['value' => 'crystal', 'label' => __('Obsidian Crystal')")
        ->toContain("['value' => 'crystal-light', 'label' => __('Obsidian Crystal Light')")
        ->and($menu)
        ->toContain("['value' => 'crystal', 'label' => __('Obsidian Crystal')]")
        ->toContain("['value' => 'crystal-light', 'label' => __('Obsidian Crystal Light')]");
});

it('exposes a topbar and mobile search trigger that opens global search', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)
        ->toContain('obsidian-search-trigger')
        ->toContain("\$dispatch('open-global-search')")
        ->toContain("__('Search projects, servers, functions…')")
        ->toContain("__('Search')");
});

it('opens the command palette on an Odoo-like functions home before searching', function () {
    $blade = file_get_contents(resource_path('views/livewire/global-search.blade.php'));

    expect($blade)
        ->toContain('get navigationFunctions()')
        ->toContain('get quickCreateActions()')
        ->toContain("__('Functions')")
        ->toContain("__('Create')")
        ->toContain('command-palette-home')
        ->toContain('command-palette-quick-grid')
        ->toContain('modalOpen && searchQuery.length < 1');
});
