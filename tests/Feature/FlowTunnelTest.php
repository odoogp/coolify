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

    $login = file_get_contents(resource_path('views/auth/login.blade.php'));
    $register = file_get_contents(resource_path('views/auth/register.blade.php'));

    expect($login)->toContain('tunnel="login"');
    expect($register)->toContain('<x-auth.shell crystal');
    expect(file_exists(public_path('media/login.mp4')))->toBeTrue();
    expect(file_exists(public_path('media/login-mobile.mp4')))->toBeTrue();

    $shell = file_get_contents(resource_path('views/components/auth/shell.blade.php'));

    expect($shell)->toContain('mobile-video="media/login-mobile.mp4"');
    expect($component)
        ->toContain('data-mobile-src')
        ->toContain("tick('a')")
        ->toContain('is-front');
    expect($styles)->toContain('.flow-tunnel.is-playing video.is-front');
    expect(file_exists(public_path('brand/getodoo-mark.png')))->toBeTrue();
    expect(file_exists(public_path('brand/getodoo-wordmark.png')))->toBeTrue();

    expect($styles)
        ->toContain('.auth-shell-split .auth-plan-layout')
        ->toContain('align-items: center')
        ->toContain('.auth-shell-terms .auth-terms-body')
        ->toContain('max-height: none')
        ->toContain('.auth-shell-split .auth-plan-package:hover::before')
        ->toContain('animation: card-border-spin 4.5s linear infinite')
        ->toContain('calc((var(--card-border-width, 1px) + 2px) * -1)')
        ->toContain('scrollbar-color: rgb(40 32 64 / 0.38) transparent')
        ->toContain('color: #f7f9fc');

    $nginx = file_get_contents(base_path('docker/production/etc/nginx/site-opts.d/http.conf'));

    expect($nginx)
        ->toContain('location ^~ /media/')
        ->toContain('public, max-age=31536000, immutable');
});
