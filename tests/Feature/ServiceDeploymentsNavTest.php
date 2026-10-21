<?php

it('puts deployments first in the odoo service navigation and entry routes', function () {
    $sidebar = file_get_contents(resource_path('views/components/service/configuration-sidebar.blade.php'));
    $configuration = file_get_contents(resource_path('views/livewire/project/service/configuration.blade.php'));
    $show = file_get_contents(app_path('Livewire/Project/Show.php'));
    $resourceIndex = file_get_contents(app_path('Livewire/Project/Resource/Index.php'));
    $routes = file_get_contents(base_path('routes/web.php'));
    $deployments = file_get_contents(app_path('Livewire/Project/Service/Deployments.php'));
    $syncJob = file_get_contents(app_path('Jobs/SyncOdooAddonsJob.php'));

    expect($routes)
        ->toContain("->name('project.service.deployments')")
        ->and($deployments)->toContain('OdooGit::ensureAddonsApplication')
        ->and($sidebar)->toContain("'Updates' => ['Deployments']")
        ->and($sidebar)->toContain('project.service.deployments')
        ->and($configuration)->toContain('project.service.deployments')
        ->and($show)->toContain('project.service.deployments')
        ->and($resourceIndex)->toContain('project.service.deployments')
        ->and($syncJob)->toContain("addLogEntry(\$e->getMessage(), 'stderr')")
        ->and($syncJob)->toContain("'finished_at' => now()");
});
