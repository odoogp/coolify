<?php

/**
 * Auth shell headers should brand the product so users know they are on Coolify.
 */
test('login register and forgot password headers are Coolify', function () {
    $login = file_get_contents(resource_path('views/auth/login.blade.php'));
    $register = file_get_contents(resource_path('views/auth/register.blade.php'));
    $forgot = file_get_contents(resource_path('views/auth/forgot-password.blade.php'));

    expect($login)
        ->toContain('title="{{ __(\'Coolify\') }}"')
        ->not->toContain('title="Welcome back"');

    expect($register)
        ->toContain('title="{{ __(\'Coolify\') }}"')
        ->not->toContain(":title=\"\$isFirstUser ? 'Create the root account' : 'Create your account'\"");

    expect($forgot)
        ->toContain('title="{{ __(\'Coolify\') }}"')
        ->not->toContain("title=\"{{ __('auth.forgot_password_heading') }}\"");
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

    expect($styles)
        ->toContain('@keyframes auth-float')
        ->toContain('@keyframes auth-drift')
        ->toContain('@keyframes auth-spin')
        ->toContain('prefers-reduced-motion: reduce')
        ->toContain('.auth-stage {')
        ->toMatch('/\.auth-stage\s*\{[^}]*position:\s*relative;/s')
        ->toMatch('/@media \(min-width: 901px\)\s*\{[^}]*\.auth-stage\s*\{[^}]*position:\s*absolute;/s')
        ->toMatch('/\.auth-shell-wide\s+\.auth-stage\s*\{[^}]*position:\s*fixed;/s')
        ->toMatch('/\.auth-shell-wide \.auth-stage-mark-slot\s*\{[^}]*position:\s*fixed;[^}]*top:\s*1\.15rem;[^}]*z-index:\s*0;/s')
        ->toMatch('/\.auth-shell-wide \.auth-shell-content\s*\{[^}]*align-items:\s*safe center;/s')
        ->not->toContain('padding: 42vh')
        ->not->toContain('grid-template-columns: minmax(0, 1.15fr)')
        ->and($shell)
        ->toContain('auth-stage-mark-slot')
        ->toContain('auth-stage-glow')
        ->toContain('auth-shell-wide')
        ->and($signup)
        ->toContain('x-auth.shell wide');
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
        ->toContain('title="{{ __(\'Coolify\') }}"')
        ->toContain('x-auth.alert')
        ->toContain('auth-guidance')
        ->not->toContain('bg-gray-50 dark:bg-base')
        ->not->toContain('!text-5xl font-extrabold');
});

test('two factor challenge page uses the shared auth shell', function () {
    $challenge = file_get_contents(resource_path('views/auth/two-factor-challenge.blade.php'));

    expect($challenge)
        ->toContain('x-auth.shell')
        ->toContain('title="{{ __(\'Coolify\') }}"')
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
        ->toContain('title="{{ __(\'Coolify\') }}"')
        ->toContain('auth-guidance')
        ->toContain('block sm:inline')
        ->not->toContain('<x-layout>')
        ->not->toContain('md:h-screen');
});

test('team invitation page uses the shared auth shell', function () {
    $invitation = file_get_contents(resource_path('views/invitation/accept.blade.php'));

    expect($invitation)
        ->toContain('x-auth.shell')
        ->toContain('title="{{ __(\'Coolify\') }}"')
        ->toContain('x-auth.alert')
        ->toContain('Accept invitation')
        ->not->toContain('bg-gray-50 dark:bg-base')
        ->not->toContain('!text-5xl font-extrabold');
});

test('plan signup keeps the crystal above the form and plans stay in one settings card', function () {
    $styles = file_get_contents(resource_path('css/app.css'));
    $plans = file_get_contents(resource_path('views/livewire/settings/getodoo-plans.blade.php'));

    expect($styles)
        ->toContain('.auth-shell-wide .auth-stage-mark-slot')
        ->toContain('transform: translateX(-50%)');

    expect($plans)
        ->toContain('settings-section title="{{ __(\'Plans\') }}" flush')
        ->toContain('data-table-header getodoo-plans-table-grid getodoo-plans-columns')
        ->toContain('getodoo-plan-row getodoo-plans-table-grid')
        ->not->toContain('data-table-row getodoo-plans-table-grid');

    expect($styles)
        ->toContain('.getodoo-plan-row')
        ->toContain('border: 1px solid var(--coollabs-fill)')
        ->toContain('13rem');
});
