<?php

it('shows the tunnel image until the video can play on terms and plan signup', function () {
    $component = file_get_contents(resource_path('views/components/flow-tunnel.blade.php'));
    $terms = file_get_contents(resource_path('views/livewire/getodoo/terms.blade.php'));
    $signup = file_get_contents(resource_path('views/livewire/getodoo/plan-signup.blade.php'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($component)
        ->toContain('media/moon-walk.jpg')
        ->toContain('media/moon-walk.mp4')
        ->toContain('webkit-playsinline')
        ->toContain('playbackRate = 0.75')
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
    expect($shell)->toContain('poster="media/login-poster.jpg"');
    expect($shell)->toContain('mobile-poster="media/login-mobile-poster.jpg"');
    expect($shell)->not->toContain('moon-walk');
    expect($shell)->toContain('class="auth-login-mark"');
    expect($shell)->toContain('class="auth-plan-mark"');
    expect($styles)->toContain('@keyframes auth-breathe');
    expect($styles)->toContain('@keyframes auth-breathe-login');
    expect($styles)->toContain('left: 25%');
    expect($styles)->toContain('.auth-plan-mark');
    expect(file_exists(public_path('media/login-poster.jpg')))->toBeTrue();
    expect(file_exists(public_path('media/login-mobile-poster.jpg')))->toBeTrue();
    expect($component)
        ->toContain('data-mobile-src')
        ->toContain('flow-tunnel-fade')
        ->toContain('flow-tunnel-poster')
        ->toContain('playsInline = true');
    expect($styles)
        ->toContain('.flow-tunnel-fade.is-dim')
        ->toContain('transition: opacity 2.8s linear')
        ->toContain('.auth-shell-login .auth-shell-content');
    expect($shell)->toContain('brand/getodoo-isologo.png');
    expect($shell)->toContain('<h1>{{ $title }}</h1>');
    expect($shell)->not->toContain('getodoo-wordmark.png');
    expect(file_exists(public_path('brand/getodoo-isologo.png')))->toBeTrue();

    expect($styles)
        ->toContain('.auth-shell-split .auth-plan-layout')
        ->toContain('align-items: center')
        ->toContain('.auth-shell-terms .auth-terms-body')
        ->toContain('max-height: none')
        ->toContain('.auth-shell-split .auth-plan-package:hover::before')
        ->toContain('animation: card-border-spin 4.5s linear infinite')
        ->toContain('calc((var(--card-border-width, 1px) + 2px) * -1)')
        ->toContain('scrollbar-color: rgb(40 32 64 / 0.38) transparent')
        ->toContain('html:has(.auth-shell)')
        ->toContain('background: transparent')
        ->toContain('color: #f7f9fc');

    $nginx = file_get_contents(base_path('docker/production/etc/nginx/site-opts.d/http.conf'));

    expect($nginx)
        ->toContain('location ^~ /media/')
        ->toContain('public, max-age=31536000, immutable')
        ->toContain('Accept-Ranges bytes always');
});
