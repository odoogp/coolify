<?php

it('uses distinct keys for the add value field modes', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/add.blade.php'));

    expect($view)
        ->toContain('x-data="{ isMultiline: $wire.entangle(\'is_multiline\') }"')
        ->toContain('<template x-if="isMultiline">')
        ->toContain('<template x-if="!isMultiline">')
        ->toContain('id="is_multiline"')
        ->toContain('<x-forms.textarea id="value" label="{{ __(\'Value\') }}" required class="font-sans" spellcheck />')
        ->toContain('wire:key="env-value-textarea"')
        ->toContain('wire:key="env-value-input"');
});

it('uses distinct keyed branches for the edit value field modes', function () {
    $view = file_get_contents(resource_path('views/livewire/project/shared/environment-variable/show.blade.php'));

    expect($view)
        ->toContain('wire:key="env-show-value-multiline-{{ $env->id }}"')
        ->toContain('wire:key="env-show-value-single-{{ $env->id }}"');
});
