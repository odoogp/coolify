<?php

it('opens a project card on the environments page', function () {
    $project = file_get_contents(app_path('Models/Project.php'));
    $index = file_get_contents(resource_path('views/livewire/project/index.blade.php'));
    $dashboard = file_get_contents(resource_path('views/livewire/dashboard.blade.php'));

    expect($project)
        ->toContain("return route('project.show', ['project_uuid' => \$this->uuid]);")
        ->not->toContain('project.resource.index')
        ->and($index)->toContain(':href="project.href"')
        ->and($dashboard)->toContain('$project->navigateTo()');
});

it('shows at most three summary cards per row and keeps the rest behind view all', function () {
    $dashboard = file_get_contents(resource_path('views/livewire/dashboard.blade.php'));
    $grids = [
        resource_path('views/livewire/dashboard.blade.php'),
        resource_path('views/livewire/project/index.blade.php'),
        resource_path('views/livewire/project/show.blade.php'),
        resource_path('views/livewire/server/index.blade.php'),
    ];

    expect($dashboard)
        ->toContain('$dashboardItemLimit = 3;')
        ->toContain("{{ __('View all') }}");

    foreach ($grids as $grid) {
        $contents = file_get_contents($grid);

        expect($contents)
            ->toContain('grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3')
            ->not->toContain('lg:grid-cols-4');
    }
});

it('lets summary card text wrap instead of using an ellipsis', function () {
    $dashboard = file_get_contents(resource_path('views/livewire/dashboard.blade.php'));
    $styles = file_get_contents(resource_path('css/app.css'));

    expect($dashboard)
        ->toContain('break-words text-[13px]! leading-4! font-semibold! text-black dark:text-fg')
        ->toContain('mt-0.5 break-words text-[11px] text-neutral-500 dark:text-fg-faint')
        ->and($styles)
        ->toContain('white-space: normal;')
        ->toContain('overflow-wrap: break-word;');
});
