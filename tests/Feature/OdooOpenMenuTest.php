<?php

it('places the open odoo menu inside the viewport on desktop and mobile', function () {
    $views = [
        resource_path('views/livewire/project/odoo-connect.blade.php'),
        resource_path('views/livewire/project/odoo-open.blade.php'),
    ];

    foreach ($views as $view) {
        $contents = file_get_contents($view);

        expect($contents)
            ->toContain('getBoundingClientRect')
            ->toContain('position: fixed')
            ->toContain('window.innerWidth')
            ->toContain('listbox-panel fixed! right-auto! bottom-auto! z-[90]!')
            ->not->toContain('listbox-panel top-full! right-0! left-auto!');
    }
});

it('teleports the connect-as users dialog to body so the top-bar blur does not clip it', function () {
    $connect = file_get_contents(resource_path('views/livewire/project/odoo-connect.blade.php'));
    $show = file_get_contents(resource_path('views/livewire/project/show.blade.php'));

    expect($connect)
        ->toContain('x-teleport="body"')
        ->toContain('z-[1200]')
        ->toContain("__('Internal Users')")
        ->and($show)
        ->toContain('x-teleport="body"')
        ->toContain("odooUsersOpen");
});
