<?php

use App\Livewire\Settings\Index;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Model::unguarded(function () {
        $this->rootTeam = Team::find(0) ?? Team::create(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
        if (! Server::find(0)) {
            Server::factory()->create(['id' => 0, 'team_id' => $this->rootTeam->id]);
        }
        if (! InstanceSettings::find(0)) {
            InstanceSettings::create(['id' => 0]);
        }
    });
    Once::flush();

    $this->user = User::factory()->create();
    $this->rootTeam->members()->attach($this->user->id, ['role' => 'admin']);
    $this->actingAs($this->user);
    session(['currentTeam' => ['id' => $this->rootTeam->id]]);
});

afterEach(function () {
    foreach (['png', 'jpg', 'webp'] as $extension) {
        $file = public_path('brand/platform-logo.'.$extension);
        if (is_file($file)) {
            unlink($file);
        }
    }
});

it('replaces the login and menu logo from settings', function () {
    Livewire::test(Index::class)
        ->set('platformLogo', UploadedFile::fake()->image('logo.png', 80, 40))
        ->call('instantSave')
        ->assertHasNoErrors();

    Once::flush();

    expect(InstanceSettings::find(0)->platform_logo)->toBe('brand/platform-logo.png')
        ->and(is_file(public_path('brand/platform-logo.png')))->toBeTrue()
        ->and(product_logo_url())->toContain('brand/platform-logo.png')
        ->and(product_mark_url())->toContain('brand/platform-logo.png');

    Livewire::test(Index::class)
        ->call('removePlatformLogo')
        ->assertHasNoErrors();

    Once::flush();

    expect(InstanceSettings::find(0)->platform_logo)->toBeNull()
        ->and(is_file(public_path('brand/platform-logo.png')))->toBeFalse()
        ->and(product_logo_url())->toContain('brand/getodoo-isologo.png')
        ->and(product_mark_url())->toContain('gpsh-logo.svg');
});

it('rejects a logo that is not an image', function () {
    Livewire::test(Index::class)
        ->set('platformLogo', UploadedFile::fake()->create('logo.txt', 20, 'text/plain'))
        ->call('instantSave')
        ->assertHasErrors(['platformLogo']);

    expect(InstanceSettings::find(0)->platform_logo)->toBeNull();
});
