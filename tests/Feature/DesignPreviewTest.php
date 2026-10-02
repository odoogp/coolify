<?php

beforeEach(function () {
    // Boarding checks config('app.env'). The route gate uses the application environment.
    config(['app.env' => 'local']);
});

test('design preview is hidden outside the local environment', function (string $path) {
    $this->app->instance('env', 'production');

    $this->get($path)->assertNotFound();
})->with([
    '/design-preview',
    '/design-preview/login',
    '/design-preview/panel',
    '/design-preview/proyectos',
    '/design-preview/servidor',
    '/design-preview/ajustes',
]);

test('design preview renders each sample screen in the local environment', function (string $path, string $marker) {
    $this->app->instance('env', 'local');

    $this->get($path)
        ->assertSuccessful()
        ->assertSee('GPSH', false)
        ->assertSee($marker, false)
        ->assertDontSee('ahmadkhancodes', false);
})->with([
    'login' => ['/design-preview', 'Iniciar sesión'],
    'panel' => ['/design-preview/panel', 'Despliegues activos'],
    'proyectos' => ['/design-preview/proyectos', 'staging.mi-empresa.example.com'],
    'servidor' => ['/design-preview/servidor', 'Traefik'],
    'ajustes' => ['/design-preview/ajustes', 'Zona horaria'],
]);

test('design preview rejects an unknown screen in the local environment', function () {
    $this->app->instance('env', 'local');

    $this->get('/design-preview/no-existe')->assertNotFound();
});
