<?php

use App\Livewire\SettingsDropdown;

it('keeps changelog in the sidebar without a dedicated theme switcher', function () {
    $navbarView = file_get_contents(resource_path('views/components/navbar.blade.php'));

    expect($navbarView)
        ->not->toContain('<span class="text-left menu-item-label">Theme</span>')
        ->not->toContain('Click to change theme.')
        ->not->toContain('cycleTheme()')
        ->toContain('this.queryTheme();');
});

it('keeps changelog and appearance options out of the preferences dropdown', function () {
    $dropdownView = file_get_contents(resource_path('views/livewire/settings-dropdown.blade.php'));

    expect($dropdownView)
        ->toContain("\$trigger === 'changelog-sidebar'")
        ->toContain('wire:click="openWhatsNewModal"')
        ->not->toContain('Appearance</div>')
        ->not->toContain("@click=\"setTheme('dark'); dropdownOpen = false\"")
        ->not->toContain("@click=\"setTheme('light'); dropdownOpen = false\"")
        ->not->toContain("@click=\"setTheme('system'); dropdownOpen = false\"");
});

it('opens and closes the changelog modal state', function () {
    $component = new SettingsDropdown;
    $component->trigger = 'changelog-sidebar';

    expect($component->trigger)->toBe('changelog-sidebar')
        ->and($component->showWhatsNewModal)->toBeFalse();

    $component->openWhatsNewModal();

    expect($component->showWhatsNewModal)->toBeTrue();

    $component->closeWhatsNewModal();

    expect($component->showWhatsNewModal)->toBeFalse();
});
