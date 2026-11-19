<?php

use App\Http\Controllers\LocaleStringsController;
use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    Once::flush();
    InstanceSettings::query()->forceCreate(['id' => 0]);
    Once::flush();
    if (User::count() === 0) {
        User::factory()->create();
    }
    $this->withoutVite();
});

test('spanish strings are served as a long-cached script', function () {
    $response = $this->get('/lang/es.js?v=1');

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8');

    expect($response->headers->get('Cache-Control'))
        ->toContain('max-age=31536000')
        ->toContain('immutable');

    $body = $response->getContent();
    expect($body)
        ->toStartWith('window.gpshStrings = {')
        ->toContain('"Welcome back":"Bienvenido de vuelta"');
});

test('unknown locales and english do not serve a dictionary', function () {
    $this->get('/lang/en.js')->assertNotFound();
    $this->get('/lang/xx.js')->assertNotFound();
    $this->get('/lang/..%2F.env.js')->assertNotFound();
});

test('the versioned url changes with the dictionary and is null for english', function () {
    $url = LocaleStringsController::urlFor('es');
    $path = lang_path('es.json');

    expect($url)->toContain('/lang/es.js?v='.filemtime($path).'-'.filesize($path))
        ->and(LocaleStringsController::urlFor('en'))->toBeNull();
});

test('pages load the dictionary by url instead of inlining it', function () {
    app()->setLocale('es');

    $html = $this->withSession(['locale' => 'es'])->get('/login')->assertSuccessful()->getContent();

    expect($html)
        ->toContain('/lang/es.js?v=')
        ->not->toContain('window.gpshStrings = {')
        ->and(strlen($html))->toBeLessThan(200_000);
});

test('nginx compresses text and caches hashed assets', function () {
    $custom = file_get_contents(base_path('docker/production/etc/nginx/conf.d/custom.conf'));
    $http = file_get_contents(base_path('docker/production/etc/nginx/site-opts.d/http.conf'));

    expect($custom)
        ->toContain('gzip on;')
        ->toContain('application/javascript')
        ->toContain('text/css');

    expect($http)
        ->toContain('location ^~ /build/assets/')
        ->toContain('location ^~ /brand/')
        ->toMatch('/location \^~ \/build\/assets\/ \{[^}]*max-age=31536000, immutable/s');
});
