<?php

it('strips leftover x-cloak after wire:navigate to prevent blank page', function () {
    $appJs = file_get_contents(resource_path('js/app.js'));

    expect($appJs)
        ->toContain("document.addEventListener('livewire:navigated'")
        ->toContain("document.addEventListener('alpine:initialized'")
        ->toContain('stripStaleXCloak')
        ->toContain("querySelectorAll('[x-cloak]')")
        ->toContain("removeAttribute('x-cloak')");
});

it('keeps x-cloak on x-show elements after wire:navigate so closed helpers stay hidden', function () {
    $appJs = file_get_contents(resource_path('js/app.js'));

    expect($appJs)
        ->toContain("if (el.hasAttribute('x-show'))")
        ->toContain('return;');
});

it('does not cloak the authenticated app shell so Alpine errors cannot blank Keys pages', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)
        ->toContain("x-data=\"{\n            open: false,")
        ->not->toMatch('/x-data="\{[^"]*open: false[\s\S]*?\}\s*"[^>]*\bx-cloak\b/');
});

it('forwards the Livewire page slot into main via an explicit Blade section', function () {
    $bridge = file_get_contents(resource_path('views/components/layout.blade.php'));
    $app = file_get_contents(resource_path('views/layouts/app.blade.php'));
    $config = file_get_contents(config_path('livewire.php'));
    $keys = file_get_contents(app_path('Livewire/Security/PrivateKey/Index.php'));

    expect($bridge)
        ->toContain("@extends('layouts.app')")
        ->toContain("@section('page')")
        ->toContain('{{ $slot }}');

    expect($app)
        ->toContain("@hasSection('page')")
        ->toContain("@yield('page')")
        ->toContain('{{ $slot }}');

    expect($config)->toContain("'layout' => 'components.layout'");

    expect($keys)->toContain("->layout('components.layout')");
});
