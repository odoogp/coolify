<?php

test('motion preview route is available in local and testing', function () {
    expect(app()->environment())->toBeIn(['local', 'testing']);

    $this->get(route('dev.motion-preview'))
        ->assertOk()
        ->assertSee('Motion preview', false)
        ->assertSee('Button press', false)
        ->assertSee('ease-motion-out', false);
});

test('motion preview route name is registered only outside production', function () {
    expect(route('dev.motion-preview'))->toEndWith('/__motion');
});
