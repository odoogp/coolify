<?php

test('the signed-in shell uses the professional canvas and violet accent', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->toContain('--color-accent: #8b7cff;')
        ->toContain('--color-panel: #07080e;')
        ->toContain('background-color: #f6f4f1;')
        ->toContain('linear-gradient(90deg, #7c5cff, #4f7dff)')
        ->toContain('linear-gradient(165deg, #5c3038 0%, #241418 58%, #141018 100%)')
        ->toContain('linear-gradient(165deg, #fff1eb 0%, #ffd4c6 100%)')
        ->toContain('.empty-state')
        ->toContain('border-radius: 28px;')
        ->not->toContain('--color-accent: #fcd452;');
});
