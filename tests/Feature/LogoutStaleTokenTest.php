<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], ['id' => 0]));
});

it('logs out and redirects to login when the logout token is stale', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $request = Request::create('/logout', 'POST');
    $request->setLaravelSession(app('session')->driver());

    $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException('CSRF token mismatch.'));

    expect($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe(route('login'))
        ->and(auth()->check())->toBeFalse();
});

it('keeps the normal 419 page for other stale forms', function () {
    $request = Request::create('/settings', 'POST');

    $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException('CSRF token mismatch.'));

    expect($response->getStatusCode())->toBe(419);
});

it('signs out through the exit animation with the current token', function () {
    $menu = file_get_contents(resource_path('views/components/top-user-menu.blade.php'));
    $app = file_get_contents(resource_path('js/app.js'));
    $mission = file_get_contents(resource_path('js/auth-mission.js'));

    expect($menu)->toContain('data-logout')
        ->and($app)->toContain('bootAppExit()')
        ->and($mission)->toContain('meta[name="csrf-token"]')
        ->and($mission)->toContain("window.location.assign('/login')");
});
