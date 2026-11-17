<?php

test('toast messages default to the top right while the activity stack stays bottom right', function () {
    $toast = file_get_contents(resource_path('views/components/toast.blade.php'));
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($toast)
        ->toContain("position: options.position ?? 'top-right'")
        ->toContain("position: 'top-right'")
        ->toContain("this.position = event.detail.position || 'top-right'")
        ->toContain("'right-4 top-4 flex-col': position === 'top-right'")
        ->toContain("'right-4 bottom-4 flex-col-reverse': position === 'bottom-right'")
        ->and($layout)
        ->toContain('id="gpsh-activity-stack"')
        ->toContain('bottom-20')
        ->toContain('sm:right-4');
});

test('toast copy button shows temporary success feedback', function () {
    $toast = file_get_contents(resource_path('views/components/toast.blade.php'));

    expect($toast)
        ->toContain('copied: false')
        ->toContain('copyToast(toast)')
        ->toContain('toast.copied = true')
        ->toContain('toast.copied = false')
        ->toContain('}, 2000)')
        ->toContain('x-show="!toast.copied"')
        ->toContain('x-show="toast.copied"')
        ->toContain("'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400': toast.copied");
});
