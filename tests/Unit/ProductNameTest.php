<?php

it('uses getodoo.sh as the visible product name', function () {
    expect(product_name())->toBe('getodoo.sh')
        ->and(str_replace('Coolify', product_name(), 'Coolify is ready'))->toBe('getodoo.sh is ready');

    $root = dirname(__DIR__, 2);
    $layout = file_get_contents($root.'/resources/views/layouts/base.blade.php');
    $app = file_get_contents($root.'/resources/views/layouts/app.blade.php');

    expect($layout)
        ->toContain('product_name()')
        ->toContain("asset('gpsh-logo.svg')")
        ->and($app)->toContain('gpsh-logo.svg')
        ->and(is_file($root.'/public/gpsh-logo.svg'))->toBeTrue();
});
