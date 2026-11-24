<?php

/*
 * Every page renders the app shell. Anything hidden until the user opens it
 * (create modals, account menu, mobile-only copies) loads lazily, so a section
 * change does not mount ~20 extra Livewire components on the server.
 */

it('lazy loads the global search create modals', function () {
    $search = file_get_contents(resource_path('views/livewire/global-search.blade.php'));

    foreach (['project.add-empty', 'team.create', 'storage.create', 'security.private-key.create', 'source.github.create'] as $component) {
        expect($search)->toMatch('#<livewire:'.preg_quote($component, '#').' key="create-modal-[a-z-]+" lazy />#');
    }
});

it('lazy loads hidden menus and mobile-only shell copies', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));
    $switcher = file_get_contents(resource_path('views/livewire/switch-team.blade.php'));

    expect($layout)
        ->toContain('<livewire:switch-team lazy />')
        ->toContain('<livewire:upgrade key="mobile-upgrade" lazy />')
        ->toContain('<livewire:gpsh-notice-bell key="notice-bell-mobile" lazy />')
        ->and($menu)
        ->toContain('<livewire:settings-dropdown trigger="account-menu" lazy />')
        ->toContain('<livewire:help lazy />')
        ->and(substr_count($switcher, '<livewire:team.create'))->toBe(substr_count($switcher, 'lazy />'));
});

it('does not ask the server again on every page for updates or the dashboard path', function () {
    $upgrade = file_get_contents(resource_path('views/livewire/upgrade.blade.php'));
    $indicator = file_get_contents(resource_path('views/livewire/deployments-indicator.blade.php'));

    expect($upgrade)->not->toContain('$wire.checkUpdate')
        ->and($indicator)
        ->toContain('wire:poll.30s')
        ->not->toContain('updateShouldShowFromPath');
});
