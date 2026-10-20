<?php

it('does not open the blank livewire dialog while an update is restarting', function () {
    $layout = file_get_contents(resource_path('views/layouts/base.blade.php'));
    $upgrade = file_get_contents(resource_path('views/livewire/upgrade.blade.php'));

    expect($layout)
        ->toContain('window.gpshBindEcho')
        ->toContain("typeof window.Echo.private === 'function'")
        ->toContain('status === 0 || body === \'\'')
        ->toContain("document.documentElement.dataset.upgrading === '1'")
        ->toContain("document.getElementById('livewire-error')?.remove()")
        ->toContain('preventDefault()')
        ->toContain('gpshShellReload')
        ->toContain("document.querySelector('main')")
        ->not->toContain('const EchoConstructor')
        ->not->toContain('dialog#livewire-error')
        ->not->toContain('window.gpshReportCutRequest');

    expect(file_get_contents(resource_path('views/livewire/layout-popups.blade.php')))
        ->toContain('x-show="popups.realtime === true"')
        ->toContain('this.popups.realtime = true');

    expect($upgrade)
        ->toContain("document.documentElement.dataset.upgrading = '1'")
        ->toContain("route('upgrade.status')")
        ->toContain("route('upgrade.log')")
        ->not->toContain('$wire.getUpgradeStatus()')
        ->not->toContain('$wire.upgradeLog()');

    // showError must clear the flag (closeErrorModal / showSuccess already did).
    expect($upgrade)->toMatch(
        '/showError\(message\)\s*\{[\s\S]*?delete document\.documentElement\.dataset\.upgrading/'
    );

    $bell = file_get_contents(resource_path('views/livewire/gpsh-notice-bell.blade.php'));

    expect($bell)
        ->toContain('wire:poll.15s')
        ->toContain('inset-x-3')
        ->toContain('x-on:gpsh-toast.window')
        // Alpine must stay nested under the Livewire root or poll morphs break the page.
        ->toMatch('/wire:poll\.15s[^>]*>\s*<div[^>]*x-data=/s');
});
