<?php

test('confirmation modal closes before dispatching an event that can open another modal', function () {
    $modal = file_get_contents(resource_path('views/components/modal-confirmation.blade.php'));

    expect($modal)->toMatch(
        '/if \(dispatchEvent\) \{\s*modalOpen = false;\s*\$nextTick\(\(\) => \$wire\.dispatch\(dispatchEventType, dispatchEventMessage\)\);/s'
    );
});

test('confirmation modal keeps an in-progress state instead of reopening an empty password field', function () {
    $modal = file_get_contents(resource_path('views/components/modal-confirmation.blade.php'));

    expect($modal)
        ->toContain("__('This can take a moment. Do not close this window.')")
        ->toContain("__('Working…')")
        ->toContain("__('Use the password of the account you are signed in with.')")
        ->toContain('result === true || result === null || result === undefined')
        ->toContain('confirmationMatches()')
        ->toContain('advanceFromStep2()')
        ->toContain('confirmPasswordStep()')
        ->toContain('genericError:')
        ->toContain('this.step = 3')
        ->toContain('runSubmit({ clearPassword: true })')
        ->not->toContain('passwordError = @js(__(\'Something went wrong. Try again.\'))')
        ->not->toMatch('/submitting = true;\s*modalOpen = false;\s*\$nextTick\(\(\) => \{\s*submitForm\(\)/s');

    expect(file_get_contents(base_path('app/Helpers/SshMultiplexingHelper.php')))
        ->toContain("['/bin/sh'")
        ->not->toContain("['/bin/bash'");
});
