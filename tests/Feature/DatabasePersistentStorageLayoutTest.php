<?php

it('gates standalone database storage actions behind the update permission', function () {
    $component = file_get_contents(app_path('Livewire/Project/Shared/Storages/All.php'));
    $view = file_get_contents(resource_path('views/livewire/project/shared/storages/all.blade.php'));

    expect($component)
        ->toContain('$this->showActionsColumn = $this->canUpdate;')
        ->and($view)
        ->toContain('@if ($showActionsColumn)')
        ->toContain('@if ($showBackupAction)')
        ->toContain('title="{{ __(\'New Scheduled Backup\') }}"')
        ->toContain('<livewire:project.database.create-scheduled-backup :database="$resource"')
        ->toContain('aria-label="{{ __(\'Configure backup\') }}"')
        ->toContain('<x-reicon name="database" class="size-4" />');
});
