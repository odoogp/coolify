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
