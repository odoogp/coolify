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

    expect($styles)
        ->toContain('.auth-shell-wide .auth-stage-mark-slot')
        ->toContain('transform: translateX(-50%)');

    expect($plans)
        ->toContain('settings-section title="{{ __(\'Plans\') }}" flush')
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
