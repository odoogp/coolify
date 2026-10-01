<?php

use App\Livewire\Settings\Whatsapp;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    Team::factory()->create(['id' => 0]);
    Once::flush();
});

it('lets the instance owner save the whatsapp support number', function () {
    $owner = User::factory()->create();
    $owner->teams()->attach(0, ['role' => 'owner']);
    $this->actingAs($owner);

    Livewire::test(Whatsapp::class)
        ->set('whatsapp_support_number', '+503 7612-0078')
        ->call('submit')
        ->assertHasNoErrors();

    expect(InstanceSettings::find(0)->whatsapp_support_number)->toBe('50376120078');
});

it('keeps an instance admin out of the whatsapp number', function () {
    $admin = User::factory()->create();
    $admin->teams()->attach(0, ['role' => 'admin']);
    $this->actingAs($admin);

    Livewire::test(Whatsapp::class)->assertRedirect(route('dashboard'));
});

it('puts github and whatsapp in the settings menu and channels the chat', function () {
    expect(file_get_contents(resource_path('views/components/settings/layout.blade.php')))
        ->toContain('settings.github')
        ->toContain('settings.whatsapp')
        ->and(file_get_contents(resource_path('views/components/whatsapp-support.blade.php')))->toContain('wa.me')
        ->and(file_get_contents(resource_path('views/components/whatsapp-support.blade.php')))->toContain('Which area do you want to talk about?')
        ->and(file_get_contents(resource_path('views/layouts/app.blade.php')))->toContain('whatsapp-support');
});
