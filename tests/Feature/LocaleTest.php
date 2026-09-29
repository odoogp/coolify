<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('spanish catalog covers every english translation key', function () {
    $english = json_decode(file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);
    $spanish = json_decode(file_get_contents(lang_path('es.json')), true, flags: JSON_THROW_ON_ERROR);

    expect(array_diff(array_keys($english), array_keys($spanish)))->toBeEmpty();
});

test('a guest can switch the session language', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    User::factory()->create();

    $this->from('/login')
        ->post('/locale', ['locale' => 'es'])
        ->assertRedirect('/login');

    $this->get('/login')
        ->assertSuccessful()
        ->assertSee('Iniciar Sesión')
        ->assertSee('Español');

    $this->from('/login')
        ->post('/locale', ['locale' => 'en'])
        ->assertRedirect('/login');

    $this->get('/login')
        ->assertSuccessful()
        ->assertSee('Login')
        ->assertDontSee('Iniciar Sesión');
});

test('an authenticated user stores the language and sees the menu in that language', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $user = User::factory()->create(['locale' => 'es']);
    $team = Team::factory()->create(['show_boarding' => false]);
    $user->teams()->attach($team, ['role' => 'owner']);
    $user->teams()->each(fn (Team $memberTeam) => $memberTeam->forceFill(['show_boarding' => false])->save());

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Panel')
        ->assertSee('Proyectos')
        ->assertSee('Servidores');

    $this->from('/')
        ->post('/locale', ['locale' => 'en'])
        ->assertRedirect('/');

    expect($user->fresh()->locale)->toBe('en')
        ->and($user->fresh()->preferredLocale())->toBe('en');

    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Dashboard')
        ->assertSee('Projects')
        ->assertSee('Servers');
});

test('an unsupported locale is rejected', function () {
    $user = User::factory()->create(['locale' => 'en']);

    $this->actingAs($user)
        ->from('/')
        ->post('/locale', ['locale' => 'fr'])
        ->assertSessionHasErrors('locale');

    expect($user->fresh()->locale)->toBe('en');
});
