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
