<?php

it('uses GPSH as the visible product name', function () {
    expect(product_name())->toBe('GPSH')
        ->and(product_text('Coolify is ready'))->toBe('GPSH is ready');

    $layout = file_get_contents(base_path('resources/views/layouts/base.blade.php'));
    $app = file_get_contents(base_path('resources/views/layouts/app.blade.php'));

    expect($layout)
        ->toContain('product_name()')
        ->toContain("asset('gpsh-logo.svg')")
        ->and($app)->toContain('gpsh-logo.svg')
        ->and(is_file(base_path('public/gpsh-logo.svg')))->toBeTrue();
});
