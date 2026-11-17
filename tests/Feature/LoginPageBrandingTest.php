<?php

/**
 * Auth shell headers brand via product_name() (GetCloud / getcloud.sh).
 */
test('login register and forgot password headers use product_name', function () {
    $login = file_get_contents(resource_path('views/auth/login.blade.php'));
    $register = file_get_contents(resource_path('views/auth/register.blade.php'));
    $forgot = file_get_contents(resource_path('views/auth/forgot-password.blade.php'));

    expect($login)
        ->toContain('title="{{ product_name() }}"')
        ->toContain("shell.dataset.authState = 'email-focus'")
        ->toContain("dataset.authState = 'submitting'")
        ->toContain("sessionStorage.setItem('gc-enter', '1')")
        ->not->toContain('title="Welcome back"');

    expect($register)
        ->toContain('title="{{ product_name() }}"')
        ->not->toContain(":title=\"\$isFirstUser ? 'Create the root account' : 'Create your account'\"");

    expect($forgot)
        ->toContain('title="{{ product_name() }}"')
        ->not->toContain("title=\"{{ __('auth.forgot_password_heading') }}\"");
});

test('login links to the terms page and does not announce disabled registration', function () {
    $login = file_get_contents(resource_path('views/auth/login.blade.php'));

    expect($login)
        ->toContain("route('getodoo.terms')")
        ->toContain("{{ __('Terms and conditions') }}")
        ->toContain('auth.register_now')
        ->not->toContain('auth.registration_disabled');
});

test('page body uses the dynamic viewport height on mobile', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->toMatch('/body\s*\{[^}]*min-height:\s*100dvh;/s')
        ->not->toMatch('/body\s*\{[^}]*@apply[^;]*min-h-screen/s');
});

test('auth scene shares one viewport and moves its objects', function () {
    $styles = file_get_contents(resource_path('css/app.css'));
    $shell = file_get_contents(resource_path('views/components/auth/shell.blade.php'));
    $signup = file_get_contents(resource_path('views/livewire/getodoo/plan-signup.blade.php'));
    $authStage = file_get_contents(resource_path('js/auth-stage.js'));
    $appJs = file_get_contents(resource_path('js/app.js'));

    expect($styles)
        ->toContain('@keyframes auth-float')
        ->toContain('@keyframes auth-drift')
        ->toContain('@keyframes auth-spin')
        ->toContain('prefers-reduced-motion: reduce')
        ->toContain('.auth-stage {')
        ->toContain('--gc-motion-base')
        ->toContain('--gc-auth-glow')
        ->toContain('.auth-stage--anime')
        ->toContain('.auth-stage--space')
        ->toContain("data-auth-state='password-focus'")
        ->toMatch('/\.auth-stage\s*\{[^}]*position:\s*relative;/s')
        ->toMatch('/@media \(min-width: 901px\)\s*\{[^}]*\.auth-stage\s*\{[^}]*position:\s*absolute;/s')
        ->toContain('.auth-shell-wide .auth-shell-content')
        ->not->toContain('padding: 42vh')
        ->and($shell)
        ->toContain('auth-login-brand')
        ->toContain('auth-open-brand')
        ->toContain('auth-dive-veil')
        ->toContain('gc-star-veil')
        ->toContain('auth-card-heading-row')
        ->toContain('auth-mission')
        ->toContain('auth-mission--bleed')
        ->toContain('auth-mission-locale')
        ->toContain('auth-mission-tagline')
        ->toContain('Artemis III · EVA')
        ->toContain('auth-shell-mission')
        ->toContain('x-auth.orbit')
        ->toContain('brand/logotipo-oscuro.svg')
        ->not->toContain('auth-mission-caption')
        ->not->toContain('Mission control')
        ->toContain('auth-shell-wide')
        ->toContain('data-auth-state')
        ->toContain('setAuthState(state)')
        ->not->toContain('auth-stage-brand')
        ->not->toContain('x-auth.gargantua')
        ->not->toContain('auth-stage-shard')
        ->not->toContain('space-station.png')
        ->not->toContain('M78 112 L100 70 L122 112')
        ->and($styles)
        ->toContain('.auth-shell-crystal .auth-card:hover::before')
        ->toContain('content: none')
        ->toContain('.auth-orbit')
        ->toContain('.auth-orbit-hero')
        ->toContain('.auth-orbit-visor-glow')
        ->toContain('.auth-orbit-sky')
        ->toContain('height: 36%')
        ->toContain('.auth-open-brand')
        ->toContain('.auth-dive-veil')
        ->toContain('.gc-star-veil')
        ->toContain('.auth-mission')
        ->toContain('.auth-mission--bleed')
        ->toContain('.auth-mission-locale')
        ->toContain('.auth-mission-tagline')
        ->toContain('.auth-shell--static')
        ->toContain('.auth-login-brand')
        ->toContain('html.gc-enter')
        ->toContain('.auth-shell-crystal .auth-card label')
        ->toContain('color: #1a1524')
        ->not->toContain('inset: 0 0 36% 0')
        ->and($signup)
        ->toContain('x-auth.shell wide')
        ->and($authStage)
        ->toContain("from 'animejs'")
        ->toContain('auth-orbit-hero-wrap')
        ->toContain('auth-orbit-visor-glow')
        ->toContain('markLoginEnter')
        ->toContain('password-focus')
        ->toContain('prefers-reduced-motion')
        ->and($appJs)
        ->toContain("import('./auth-stage.js')")
        ->toContain("import('./app-enter.js')");

    $orbit = file_get_contents(resource_path('views/components/auth/orbit.blade.php'));
    $loginPage = file_get_contents(resource_path('views/auth/login.blade.php'));
    expect($orbit)
        ->toContain('mission-hero.jpg')
        ->toContain('auth-orbit-visor-glow')
        ->toContain('auth-orbit-sky')
        ->toContain('auth-orbit-stars--far')
        ->toContain('auth-orbit-stars--mid')
        ->toContain('auth-orbit-stars--near')
        ->toContain('auth-orbit-meteor')
        ->toContain('auth-orbit-open-veil')
        ->not->toContain('mission-earth.jpg')
        ->not->toContain('mission-nebula.jpg')
        ->not->toContain('auth-orbit-astronaut')
        ->not->toContain('earth-disk.jpg');
    expect($styles)
        ->toContain('.auth-orbit-meteor')
        ->toContain('.auth-orbit-meteor-trail')
        ->toContain('.auth-orbit-open-veil')
        ->toContain('.auth-shell--opening');
    expect($authStage)
        ->toContain('bootStarfield')
        ->toContain('playAuthOpen')
        ->toContain('auth-open-brand')
        ->toContain('auth-dive-veil')
        ->toContain('Plunge into the starfield')
        ->toContain('bootImmersiveParallax')
        ->toContain('deviceorientation')
        ->toContain('auth-shell--opening')
        ->toContain('auth-shell--static')
        ->toContain('createTimer')
        ->toContain('auth-orbit-meteor');

    expect($loginPage)
        ->toContain('New to :product?')
        ->toContain('product_name()')
        ->not->toContain('New to Coolify?');
    expect(file_exists(public_path('brand/illustrations/space/mission-hero.jpg')))->toBeTrue();
    expect($authStage)
        ->not->toContain('auth-orbit-nebula')
        ->not->toContain('auth-orbit-earth');

    $appEnter = file_get_contents(resource_path('js/app-enter.js'));
    $appLayout = file_get_contents(resource_path('views/layouts/app.blade.php'));
    $baseLayout = file_get_contents(resource_path('views/layouts/base.blade.php'));

    expect($appEnter)
        ->toContain("from 'animejs'")
        ->toContain('emerge-from-stars')
        ->toContain('isMobile')
        ->toContain('bootAppEnter')
        ->toContain('markLoginEnter');

    expect($appLayout)
        ->toContain('data-gc-enter="sidebar"')
        ->toContain('data-gc-enter="main"')
        ->toContain('data-gc-enter="topbar"')
        ->toContain('data-gc-enter="veil"')
        ->toContain('gc-star-veil');

    expect($baseLayout)
        ->toContain("sessionStorage.getItem('gc-enter')")
        ->toContain("classList.add('gc-enter')");
});

test('auth pages use the Coollabs purple background glow', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->toMatch('/\.auth-shell\s*\{[^}]*color-mix\(in oklab, var\(--color-coollabs\) 9%, transparent\)/s')
        ->not->toMatch('/\.auth-shell\s*\{[^}]*color-mix\(in oklab, var\(--color-accent\) 9%, transparent\)/s');
});

test('error pages use the Coollabs purple background glow', function () {
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($styles)
        ->toMatch('/\.error-shell\s*\{[^}]*color-mix\(in oklab, var\(--color-coollabs\) 9%, transparent\)/s')
        ->not->toMatch('/\.error-shell\s*\{[^}]*color-mix\(in oklab, var\(--color-accent\) 9%, transparent\)/s');
});

test('confirm password page uses the shared auth shell', function () {
    $confirm = file_get_contents(resource_path('views/auth/confirm-password.blade.php'));

    expect($confirm)
        ->toContain('x-auth.shell')
        ->toContain('product_name()')
        ->toContain('x-auth.alert')
        ->toContain('auth-guidance')
        ->not->toContain('bg-gray-50 dark:bg-base')
        ->not->toContain('!text-5xl font-extrabold');
});

test('two factor challenge page uses the shared auth shell', function () {
    $challenge = file_get_contents(resource_path('views/auth/two-factor-challenge.blade.php'));

    expect($challenge)
        ->toContain('x-auth.shell')
        ->toContain('product_name()')
        ->toContain('x-auth.alert')
        ->toContain('auth-guidance')
        ->toContain('Verify and continue')
        ->not->toContain('bg-gray-50 dark:bg-base')
        ->not->toContain('!text-5xl font-extrabold');
});

test('email verification page uses the shared auth shell', function () {
    $verification = file_get_contents(resource_path('views/auth/verify-email.blade.php'));

    expect($verification)
        ->toContain('x-layout-simple')
        ->toContain('x-auth.shell')
        ->toContain('product_name()')
        ->toContain('auth-guidance')
        ->toContain('block sm:inline')
        ->not->toContain('<x-layout>')
        ->not->toContain('md:h-screen');
});

test('team invitation page uses the shared auth shell', function () {
    $invitation = file_get_contents(resource_path('views/invitation/accept.blade.php'));

    expect($invitation)
        ->toContain('x-auth.shell')
        ->toContain('product_name()')
        ->toContain('x-auth.alert')
        ->toContain('Accept invitation')
        ->not->toContain('bg-gray-50 dark:bg-base')
        ->not->toContain('!text-5xl font-extrabold');
});

test('auth field icons sit outside the input so browser autofill cannot remove them', function () {
    $styles = file_get_contents(resource_path('css/app.css'));
    $input = file_get_contents(resource_path('views/components/forms/input.blade.php'));

    expect($input)
        ->toContain('auth-field-icon')
        ->toContain('class="relative auth-field-icon"');

    expect($styles)
        ->toContain('.auth-shell .auth-card .auth-field-icon:has(> input[type=\'email\'])::before')
        ->toContain('.auth-shell .auth-card .auth-field-icon:has(> input[type=\'text\'])::before')
        ->toContain('.auth-shell .auth-card .auth-field-icon > input')
        ->toContain('background-image: none !important')
        ->toContain('pointer-events: none');
});

test('submit loading spinner stays hidden until the wire request runs', function () {
    $button = file_get_contents(resource_path('views/components/forms/button.blade.php'));
    $loading = file_get_contents(resource_path('views/components/loading-on-button.blade.php'));
    $signup = file_get_contents(resource_path('views/livewire/getodoo/plan-signup.blade.php'));

    expect($button)
        ->toContain('wire:loading.class.remove="hidden"')
        ->toContain('wire:loading.class="inline-flex"')
        ->toContain('class="hidden"');

    expect($loading)
        ->toContain("\$attributes->class(['shrink-0', 'items-center', 'gap-1.5'])")
        ->not->toContain("merge(['class' => 'inline-flex");

    expect($signup)
        ->toContain('wire:submit="register"')
        ->toContain('wire:target="register"')
        ->not->toContain(':show-loading-indicator="false"');
});

test('plan signup keeps the crystal above the form and plans stay in one settings card', function () {
    $styles = file_get_contents(resource_path('css/app.css'));
    $plans = file_get_contents(resource_path('views/livewire/settings/getodoo-plans.blade.php'));

    expect($plans)
        ->toContain("title=\"{{ \$showArchived ? __('Archived plans') : __('Plans') }}\"")
        ->toContain('flush>')
        ->toContain('data-table-header getodoo-plans-table-grid')
        ->toContain('data-table-row getodoo-plans-table-grid')
        ->not->toContain('getodoo-plan-row');

    expect($styles)
        ->toContain('.getodoo-plans-link-cell')
        ->toContain('.getodoo-plans-row-actions')
        ->toContain('.getodoo-plans-row-actions .getodoo-plans-action.button')
        ->toContain('flex-wrap: wrap')
        ->toContain("'name price'")
        ->toContain("'scope members'")
        ->toContain("'link link'")
        ->toContain('.data-table-header.getodoo-plans-table-grid')
        ->toMatch('/\.getodoo-plans-link-cell\s*>\s*\.input\s*\{[^}]*flex:\s*1\s+1\s+12rem;/s');

    expect($plans)
        ->toContain("{{ __('Members') }}")
        ->toContain('showPlanMembers')
        ->toContain('showArchivedPlans')
        ->toContain('archivePlan')
        ->toContain('deletePlan')
        ->toContain('getodoo-plans-link-cell')
        ->toContain('button button-highlighted getodoo-plans-action')
        ->toContain("{{ __('Copy') }}")
        ->toContain("{{ __('Archive plan') }}")
        ->toContain("{{ __('Delete') }}")
        ->toContain("{{ __('Archived') }}")
        ->toContain(':show-loading-indicator="false"')
        ->not->toContain('teams_count === 0')
        ->not->toContain('hover:max-w-32')
        ->not->toContain('group/tool')
        ->not->toContain('top-full z-20');
});
