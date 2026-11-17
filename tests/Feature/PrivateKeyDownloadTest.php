<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    if (! InstanceSettings::query()->whereKey(0)->exists()) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->save();
    }

    Storage::fake('ssh-keys');

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);
    $this->actingAs($this->user);

    $this->privateKey = PrivateKey::factory()->create([
        'name' => 'Launch key',
        'team_id' => $this->team->id,
    ]);
});

it('downloads the private key file for an admin of the team', function () {
    $response = $this->get(route('security.private-key.download', [
        'private_key_uuid' => $this->privateKey->uuid,
    ]));

    $response->assertOk();
    $response->assertDownload('launch-key.key');

    expect($response->streamedContent())
        ->toBe($this->privateKey->fresh()->private_key)
        ->toContain('BEGIN OPENSSH PRIVATE KEY')
        ->not->toStartWith('ssh-ed25519');

    $response->assertHeader('Cache-Control', 'no-store, private');
});

it('shows the download link on the private key page', function () {
    $this->get(route('security.private-key.show', [
        'private_key_uuid' => $this->privateKey->uuid,
    ]))
        ->assertSuccessful()
        ->assertSee('Download key')
        ->assertSee('/security/private-key/'.$this->privateKey->uuid.'/download');
});

it('refuses the download to a team member', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);

    session(['currentTeam' => $this->team]);
    $this->actingAs($member);

    $this->get(route('security.private-key.download', [
        'private_key_uuid' => $this->privateKey->uuid,
    ]))->assertForbidden();
});

it('hides a private key that belongs to another team', function () {
    $otherTeam = Team::factory()->create();
    $otherUser = User::factory()->create();
    $otherTeam->members()->attach($otherUser->id, ['role' => 'owner']);

    session(['currentTeam' => $otherTeam]);
    $this->actingAs($otherUser);

    $this->get(route('security.private-key.download', [
        'private_key_uuid' => $this->privateKey->uuid,
    ]))->assertNotFound();
});

it('asks a guest to sign in before downloading a private key', function () {
    auth()->logout();

    $this->get(route('security.private-key.download', [
        'private_key_uuid' => $this->privateKey->uuid,
    ]))->assertRedirect(route('login'));
});
