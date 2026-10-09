<?php

it('does not open the blank livewire dialog while an update is restarting', function () {
    $layout = file_get_contents(resource_path('views/layouts/base.blade.php'));
    $upgrade = file_get_contents(resource_path('views/livewire/upgrade.blade.php'));

    expect($layout)
        ->toContain("document.getElementById('livewire-error')?.remove()")
        ->toContain('dialog#livewire-error')
        ->toContain("rgb(23,22,26)")
        ->toContain('preventDefault()')
        ->toContain("route('notices.request-failure')")
        ->toContain('window.gpshReportCutRequest = reportCutRequest')
        ->not->toContain("document.documentElement.dataset.upgrading !== '1'");

    expect(file_get_contents(resource_path('views/livewire/layout-popups.blade.php')))
        ->toContain('x-show="popups.realtime === true"')
        ->toContain('this.popups.realtime = true');

    expect($upgrade)
        ->toContain("document.documentElement.dataset.upgrading = '1'")
        ->toContain("route('upgrade.status')")
        ->toContain("route('upgrade.log')")
        ->not->toContain('$wire.getUpgradeStatus()')
        ->not->toContain('$wire.upgradeLog()');

    expect(file_get_contents(resource_path('views/livewire/gpsh-notice-bell.blade.php')))
        ->toContain('fixed top-16 right-4');
});
