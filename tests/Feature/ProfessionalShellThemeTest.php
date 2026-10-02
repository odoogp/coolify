<?php

test('the signed-in shell uses the professional canvas and violet accent', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->toContain('--color-accent: #8b7cff;')
        ->toContain('--color-panel: #07080e;')
        ->toContain('background-color: #f6f4f1;')
        ->toContain('linear-gradient(90deg, #7c5cff, #4f7dff)')
        ->toContain('backdrop-filter: blur(40px) saturate(1.6);')
        ->toContain('color: #1d1d1f;')
        ->toContain('color: #f5f5f7;')
        ->toContain('.empty-state')
        ->toContain('border-radius: 28px;')
        ->not->toContain('linear-gradient(165deg, #ffb199 0%, #e25b45 55%, #9a3412 100%)')
        ->not->toContain('--color-accent: #fcd452;');
});

test('crystal is its own appearance and keeps readable type on colored glass', function () {
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));
    $appearance = file_get_contents(resource_path('views/livewire/profile/appearance.blade.php'));
    $layout = file_get_contents(resource_path('views/layouts/base.blade.php'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($menu)
        ->toContain("['value' => 'crystal', 'label' => __('Crystal')]")
        ->toContain("['value' => 'crystal-light', 'label' => __('Crystal light')]")
        ->and($appearance)
        ->toContain("['value' => 'crystal', 'label' => __('Crystal')")
        ->and($layout)
        ->toContain("theme === 'crystal'")
        ->toContain("theme === 'crystal-light'")
        ->and($styles)
        ->toContain('html[data-theme="crystal"]')
        ->toContain('radial-gradient(90% 75% at var(--spot-x) var(--spot-y), #ff9a7a 0%, rgb(226 85 60 / 0.28) 42%, transparent 68%)')
        ->toContain('color: #ffffff;')
        ->toContain('--spot-x: 28%;')
        ->toContain('html[data-theme="crystal-light"]')
        ->toContain('color: #1d1d1f;')
        ->and(file_get_contents(resource_path('js/app.js')))
        ->toContain('placeTileSpot')
        ->toContain('is-pressed');
});
