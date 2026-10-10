<?php

it('shows the tunnel image until the video can play on terms and plan signup', function () {
    $component = file_get_contents(resource_path('views/components/flow-tunnel.blade.php'));
    $terms = file_get_contents(resource_path('views/livewire/getodoo/terms.blade.php'));
    $signup = file_get_contents(resource_path('views/livewire/getodoo/plan-signup.blade.php'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($component)
        ->toContain('media/moon-walk.jpg')
        ->toContain('media/moon-walk.mp4')
        ->toContain("@playing=\"playing = true\"")
        ->toContain('absolute inset-0 h-full w-full -z-10 pointer-events-none');

    expect(file_exists(public_path('media/moon-walk.jpg')))->toBeTrue();
    expect(file_exists(public_path('media/moon-walk.mp4')))->toBeTrue();

    expect($terms)->toContain('tunnel="full"');
    expect($signup)->toContain('tunnel="split"');

    expect($styles)
        ->toContain('.auth-shell-split .auth-plan-layout')
        ->toContain('align-items: center')
        ->toContain('.auth-shell-tunnel:not(.auth-shell-split) .auth-terms-body')
        ->toContain('max-height: none')
        ->toContain('.auth-shell-split .auth-plan-package::before')
        ->toContain('animation: card-border-spin 4.5s linear infinite')
        ->toContain('color: #f7f9fc');

    $nginx = file_get_contents(base_path('docker/production/etc/nginx/site-opts.d/http.conf'));

    expect($nginx)
        ->toContain('location ^~ /media/')
        ->toContain('public, max-age=31536000, immutable');
});
