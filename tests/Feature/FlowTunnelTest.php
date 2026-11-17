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
        ->toContain('data-rate="{{ $rate }}"')
        ->toContain('response.blob()')
        ->toContain('createObjectURL')
        ->toContain('readyState >= 2')
        ->toContain('data-mobile-src')
        ->toContain("addEventListener('ended'")
        ->not->toContain("addEventListener('pause'")
        ->toContain('absolute inset-0 h-full w-full -z-10 pointer-events-none');

    expect(file_exists(public_path('media/moon-walk.jpg')))->toBeTrue();
    expect(file_exists(public_path('media/moon-walk.mp4')))->toBeTrue();

    expect($terms)->toContain('tunnel="full"');
    expect($signup)->toContain('tunnel="split"');

    $login = file_get_contents(resource_path('views/auth/login.blade.php'));
    $register = file_get_contents(resource_path('views/auth/register.blade.php'));

    expect($login)->toContain('tunnel="login"');
    expect($register)->toContain('<x-auth.shell crystal');

    $shell = file_get_contents(resource_path('views/components/auth/shell.blade.php'));

    // Login uses the NASA/orbit stage + in-card brand, not the earth video tunnel.
    expect($shell)->toContain('auth-stage--space');
    expect($shell)->toContain('x-auth.orbit');
    expect($shell)->toContain('auth-login-brand');
    expect($shell)->toContain('auth-open-brand');
    expect($shell)->toContain('auth-dive-veil');
    expect($shell)->toContain('gc-star-veil');
    expect($shell)->toContain('auth-card-heading-row');
    expect($shell)->toContain('auth-mission-locale');
    expect($shell)->toContain('auth-mission--bleed');
    expect($shell)->toContain('auth-mission-tagline');
    expect($shell)->toContain('brand/logotipo-oscuro.svg');
    expect($shell)->not->toContain('auth-mission-caption');
    expect($shell)->not->toContain('auth-stage-brand');
    expect($shell)->not->toContain('x-auth.gargantua');
    expect($shell)->not->toContain('moon-walk');
    expect($shell)->not->toContain('media/login.mp4');
    expect($shell)->not->toContain('auth-stage-shard');
    expect($shell)->not->toContain('space-station.png');
    expect($shell)->not->toContain('auth-login-mark');
    expect($shell)->not->toContain('auth-plan-mark');
    expect($styles)->toContain('.auth-stage--space');
    expect($styles)->toContain('.auth-login-brand');
    expect($styles)->toContain('.auth-orbit');
    expect($styles)->toContain('.auth-orbit-hero');
    expect($styles)->toContain('.auth-mission');
    expect($styles)->toContain('html.gc-enter');
    expect($styles)->toContain('.gc-enter-panel');
    expect($styles)->not->toContain('@keyframes auth-breathe');
    expect($styles)->not->toContain('.auth-plan-mark');
    expect(file_exists(resource_path('views/components/auth/orbit.blade.php')))->toBeTrue();
    expect(file_exists(public_path('brand/illustrations/space/mission-hero.jpg')))->toBeTrue();
    expect(file_exists(public_path('brand/logotipo.svg')))->toBeTrue();

    $orbit = file_get_contents(resource_path('views/components/auth/orbit.blade.php'));
    expect($orbit)
        ->toContain('mission-hero.jpg')
        ->toContain('auth-orbit-hero')
        ->toContain('auth-orbit-visor-glow')
        ->toContain('auth-orbit-meteor')
        ->not->toContain('mission-earth.jpg')
        ->not->toContain('auth-orbit-astronaut')
        ->not->toContain('earth-disk.jpg');
    expect($authStage = file_get_contents(resource_path('js/auth-stage.js')))
        ->toContain("from 'animejs'")
        ->toContain('auth-orbit-hero-wrap')
        ->toContain('markLoginEnter')
        ->toContain('bootStarfield')
        ->toContain('createTimer')
        ->not->toContain('auth-orbit-nebula');
    expect($component)
        ->toContain('data-mobile-src')
        ->toContain('flow-tunnel-fade')
        ->toContain('flow-tunnel-poster')
        ->toContain('playsInline = true');
    expect($styles)
        ->toContain('.flow-tunnel-fade {')
        ->toContain('.auth-shell-mission .auth-shell-content');
    expect($shell)->toContain('auth-login-heading');
    expect($shell)->toContain('auth-login-lede');
    expect($styles)->toContain('.auth-isologo {');
    expect($styles)->toContain('.auth-isologo img');
    expect($shell)->toContain('product_logo_url()');
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
