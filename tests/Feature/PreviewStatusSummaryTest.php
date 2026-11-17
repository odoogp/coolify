<?php

use Illuminate\Support\Facades\Blade;

it('aggregates preview container and health check status', function () {
    $html = Blade::render('<x-status-summary status="running:unknown" title="Preview status" />');

    expect($html)
        ->toContain('Preview status')
        ->toContain('Container')
        ->toContain('Running (no healthcheck)')
        ->toContain('Healthcheck')
        ->toContain('Not configured')
        ->toContain('aria-label="{{ __(\'About unconfigured healthchecks\') }}"')
        ->toContain('class="relative inline-flex align-middle"')
        ->toContain('Traffic can still be routed to the container')
        ->toContain('aria-haspopup="menu"')
        ->toContain('right-auto! left-0!')
        ->toContain('w-[min(16rem,calc(100vw-1.5rem))]!');
});

it('shows degraded aggregate service status as a warning', function () {
    $html = Blade::render('<x-status-summary status="degraded:unhealthy" title="Service status" container-name="Containers" />');
    $summaryButton = str($html)->between('<button', '</button>')->toString();

    expect($view)
        ->toContain('<x-status-summary :status="data_get($preview, \'status\')" title="{{ __(\'Preview status\') }}" />')
        ->not->toContain('<x-status.running :status="data_get($preview, \'status\')" />');
});

it('links the healthcheck row to the healthcheck page and keeps the helper outside the link', function () {
    $html = Blade::render('<x-status-summary status="running:unknown" healthcheck-url="https://coolify.test/healthcheck" />');
    $link = str($html)->after('href="https://coolify.test/healthcheck"')->before('</a>')->toString();

    expect($html)->toContain('href="https://coolify.test/healthcheck"')
        ->and($link)
        ->toContain('Healthcheck')
        ->toContain('Not configured')
        ->not->toContain('About unconfigured healthchecks')
        ->and(str($html)->after('</a>')->toString())
        ->toContain('aria-label="About unconfigured healthchecks"');
});

it('does not link the healthcheck row without a healthcheck url', function () {
    $html = Blade::render('<x-status-summary status="running:healthy" />');

    expect($databaseStatus)
        ->toContain('<x-status-summary :status="$database->status" title="{{ __(\'Database status\') }}" />')
        ->and($serviceStatus)
        ->toContain('<x-status-summary :status="$service->status" title="{{ __(\'Service status\') }}" container-name="Containers" />');
});

it('groups preview deployment actions in a dropdown', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/previews.blade.php'));

    expect($view)
        ->toContain('title="{{ __(\'Preview actions\') }}"')
        ->toContain('preview-stop-trigger-{{ data_get($preview, \'pull_request_id\') }}')
        ->toContain('preview-delete-trigger-{{ data_get($preview, \'pull_request_id\') }}')
        ->not->toContain('<x-slot:customButton>');
});

it('marks external preview links and groups logs in a dropdown', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/previews.blade.php'));

    expect($view)
        ->toContain('title="{{ __(\'Open preview in a new tab\') }}"')
        ->toContain('title="{{ __(\'Open pull request in a new tab\') }}"')
        ->toContain('<x-reicon name="external-link"')
        ->toContain('title="{{ __(\'Preview logs\') }}"')
        ->toContain('Deployment logs')
        ->toContain('Runtime logs')
        ->not->toContain('Application logs');
});

it('places links and logs dropdowns beside preview actions', function () {
    $view = file_get_contents(resource_path('views/livewire/project/application/previews.blade.php'));
    $controls = str($view)->after('id="preview-header-controls')->before('<div class="hidden" aria-hidden="true">')->toString();

    expect($controls)
        ->toContain('title="{{ __(\'Preview links\') }}"')
        ->toContain('title="{{ __(\'Preview logs\') }}"')
        ->toContain('title="{{ __(\'Preview actions\') }}"');
});
