<?php

use App\Livewire\Storage\Create;
use App\Models\S3Storage;
use App\Models\Team;
use App\Models\User;
use App\Policies\S3StoragePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('s3 storage model resolves its registered policy through the gate', function () {
    expect(Gate::getPolicyFor(S3Storage::class))->toBeInstanceOf(S3StoragePolicy::class);
});

test('s3 storage create ability is enforced through the registered policy', function () {
    $team = Team::factory()->create();

    $owner = User::factory()->create();
    $owner->teams()->attach($team, ['role' => 'owner']);

    $admin = User::factory()->create();
    $admin->teams()->attach($team, ['role' => 'admin']);

    $member = User::factory()->create();
    $member->teams()->attach($team, ['role' => 'member']);

    $this->actingAs($owner);
    session(['currentTeam' => $team]);

    expect($owner->can('create', S3Storage::class))->toBeTrue()
        ->and($admin->can('create', S3Storage::class))->toBeFalse()
        ->and($member->can('create', S3Storage::class))->toBeFalse();
});

test('a team admin can load the s3 form that is mounted on every page', function () {
    $team = Team::factory()->create();
    $admin = User::factory()->create();
    $admin->teams()->attach($team, ['role' => 'admin']);

    $this->actingAs($admin);
    session(['currentTeam' => $team]);

    Livewire::test(Create::class)
        ->assertOk()
        ->set('name', 'Backups')
        ->set('description', 'Team backup storage')
        ->set('region', 'us-east-1')
        ->set('key', 'access-key')
        ->set('secret', 'secret-key')
        ->set('bucket', 'coolify-backups')
        ->set('endpoint', 'https://s3.us-east-1.amazonaws.com')
        ->call('submit')
        ->assertDispatched('error');

    expect(S3Storage::query()->where('name', 'Backups')->exists())->toBeFalse();
});

test('s3 storage validate connection ability is enforced through the registered policy', function () {
    $team = Team::factory()->create();

    $owner = User::factory()->create();
    $owner->teams()->attach($team, ['role' => 'owner']);

    $admin = User::factory()->create();
    $admin->teams()->attach($team, ['role' => 'admin']);

    $member = User::factory()->create();
    $member->teams()->attach($team, ['role' => 'member']);

    $storage = S3Storage::create([
        'team_id' => $team->id,
        'name' => 'Backups',
        'description' => 'Team backup storage',
        'region' => 'us-east-1',
        'key' => 'access-key',
        'secret' => 'secret-key',
        'bucket' => 'coolify-backups',
        'endpoint' => 'https://s3.us-east-1.amazonaws.com',
    ]);

    expect($owner->can('validateConnection', $storage))->toBeTrue()
        ->and($admin->can('validateConnection', $storage))->toBeFalse()
        ->and($member->can('validateConnection', $storage))->toBeFalse();
});
