<?php

it('renders the flow tunnel on terms and splits the plan signup page', function () {
    $component = file_get_contents(resource_path('views/components/flow-tunnel.blade.php'));
    $script = file_get_contents(resource_path('js/flow-tunnel.js'));
    $terms = file_get_contents(resource_path('views/livewire/getodoo/terms.blade.php'));
    $signup = file_get_contents(resource_path('views/livewire/getodoo/plan-signup.blade.php'));
    $app = file_get_contents(resource_path('js/app.js'));

    expect($component)
        ->toContain('x-data="flowTunnel(')
        ->toContain('absolute inset-0 h-full w-full -z-10 pointer-events-none')
        ->toContain('x-ref="canvas"');

    expect($script)
        ->toContain('initializeFlowTunnel')
        ->toContain('Alpine.data(\'flowTunnel\'')
        ->toContain('requestAnimationFrame')
        ->toContain('WEBGL_lose_context')
        ->toContain('removeEventListener(\'pointermove\'')
        ->toContain('uMouse')
        ->toContain('prefers-reduced-motion');

    expect($app)->toContain('initializeFlowTunnel()');
    expect($terms)->toContain('tunnel="full"');
    expect($signup)->toContain('tunnel="split"');
});
