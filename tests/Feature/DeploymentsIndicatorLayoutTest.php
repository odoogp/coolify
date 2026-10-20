<?php

it('stacks the updates indicator on the right with notice toasts', function () {
    $indicatorView = file_get_contents(resource_path('views/livewire/deployments-indicator.blade.php'));
    $layoutView = file_get_contents(resource_path('views/layouts/app.blade.php'));
    $noticeView = file_get_contents(resource_path('views/livewire/gpsh-notice-bell.blade.php'));

    expect($layoutView)
        ->toContain('id="gpsh-activity-stack"')
        ->toContain('sm:right-4')
        ->toContain('<livewire:deployments-indicator />')
        ->toContain('<div x-data="{');

    expect(strpos($layoutView, 'id="gpsh-activity-stack"'))
        ->toBeLessThan(strpos($layoutView, '<livewire:deployments-indicator />'));

    expect($indicatorView)
        ->toContain('Updating…')
        ->toContain(':count updates…')
        ->toContain('Updating modules')
        ->toContain('Updates in progress')
        ->not->toContain('Str::plural(\'deployment\'')
        ->not->toContain('transition-[left]')
        ->not->toContain('lg:left-56')
        ->not->toContain('fixed bottom-0');

    expect($noticeView)
        ->toContain('x-teleport="#gpsh-activity-stack"')
        ->toContain('order-first')
        ->not->toContain('fixed inset-x-3 top-16');
});

it('uses the redesigned elevated surface and status badge patterns', function () {
    $indicatorView = file_get_contents(resource_path('views/livewire/deployments-indicator.blade.php'));

    expect($indicatorView)
        ->toContain('var(--coollabs-elevated)')
        ->toContain('var(--coollabs-line)')
        ->toContain('var(--shadow-modal)')
        ->toContain('var(--coollabs-hairline)')
        ->toContain('<x-status-badge')
        ->toContain('name="chevron-down"')
        ->toContain('Updating modules')
        ->toContain('Waiting')
        ->toContain('dark:text-fg')
        ->toContain('dark:border-coolgray-300')
        ->toContain('$this->shouldShow')
        ->toContain('updateShouldShowFromPath')
        ->toContain('livewire:navigated')
        ->not->toContain('dark:bg-coolgray-100')
        ->not->toContain('dark:bg-coolgray-200')
        ->not->toContain('text-gray-800')
        ->not->toContain("str_replace('_', ' ', \$deployment->status)");
});

it('uses solid surfaces in every deployments indicator state', function () {
    $indicatorView = file_get_contents(resource_path('views/livewire/deployments-indicator.blade.php'));

    expect($indicatorView)
        ->not->toContain('reduceOpacity')
        ->not->toContain('transition-opacity')
        ->not->toContain('x-transition:enter-start="opacity-0')
        ->not->toContain('x-transition:leave-end="opacity-0')
        ->not->toContain('dark:bg-white/[')
        ->not->toContain('dark:hover:bg-white/[')
        ->not->toContain('dark:border-white/[');
});

it('announces and restarts odoo after a modules sync finishes', function () {
    $restart = file_get_contents(app_path('Jobs/RestartOdooBranchJob.php'));

    expect($restart)
        ->toContain('GpshNotices::announceRestarting')
        ->toContain('RestartServiceApplication::run')
        ->toContain('OdooGit::refreshAppsList');
});

it('refreshes the odoo apps list after modules land in extra-addons', function () {
    $source = file_get_contents(app_path('Support/OdooGit.php'));

    expect($source)
        ->toContain('function refreshAppsList')
        ->toContain("update_list()");
});
