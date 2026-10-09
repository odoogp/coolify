<?php

use App\Livewire\Profile\Index as ProfileIndex;
use App\Livewire\Team\InviteLink;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $this->root = Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
});

it('lets a user save their country on the profile', function () {
    $user = User::factory()->create(['country_iso' => null]);
    $this->root->members()->attach($user->id, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $this->root]);

    Livewire::test(ProfileIndex::class)
        ->set('name', $user->name)
        ->set('country', 'Guatemala')
        ->call('submit')
        ->assertHasNoErrors();

    expect($user->fresh()->country_iso)->toBe('GT');
});

it('inherits the inviter country when the invite leaves country empty', function () {
    $owner = User::factory()->create(['country_iso' => 'SV']);
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(InviteLink::class)
        ->set('email', 'new-admin@example.com')
        ->set('role', 'admin')
        ->set('country', '')
        ->call('viaLink')
        ->assertHasNoErrors();

    $invited = User::query()->where('email', 'new-admin@example.com')->first();
    expect($invited)->not->toBeNull()
        ->and($invited->country_iso)->toBe('SV');
});

it('uses the country selected on the invitation when provided', function () {
    $owner = User::factory()->create(['country_iso' => 'SV']);
    $this->root->members()->attach($owner->id, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->root]);

    Livewire::test(InviteLink::class)
        ->set('email', 'gt-admin@example.com')
        ->set('role', 'admin')
        ->set('country', 'Guatemala')
        ->call('viaLink')
        ->assertHasNoErrors();

    expect(User::query()->where('email', 'gt-admin@example.com')->value('country_iso'))->toBe('GT');
});
