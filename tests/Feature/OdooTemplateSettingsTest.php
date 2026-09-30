<?php

use App\Livewire\Settings\Odoo;
use App\Models\InstanceSettings;
use App\Models\OdooComposeTemplate;
use App\Models\Team;
use App\Models\User;
use App\Support\OdooVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->root = Team::factory()->make(['name' => 'Root']);
    $this->root->id = 0;
    $this->root->save();

    $this->user = User::factory()->create();
    $this->user->teams()->attach($this->root, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->root]);
});

it('edits the compose and postgresql version for one odoo version', function () {
    $component = Livewire::test(Odoo::class)
        ->assertSee('Odoo templates')
        ->assertSet('version', '18')
        ->assertSet('postgresVersion', '16-alpine');

    expect($component->get('compose'))->toContain('image: postgres:16-alpine');

    $component->set('postgresVersion', '17-alpine')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('postgresVersion', '17-alpine');

    $saved = OdooComposeTemplate::query()->where('version', '18')->first();

    expect($saved)->not->toBeNull()
        ->and($saved->compose)->toContain('image: odoo:18')
        ->and($saved->compose)->toContain('image: postgres:17-alpine')
        ->and($saved->compose)->not->toContain('postgres:16-alpine')
        ->and(OdooComposeTemplate::composeFor('20'))->toBeNull()
        ->and(OdooVersion::current(OdooComposeTemplate::defaultCompose('20')))->toBe('20');
});

it('keeps each odoo version template separate', function () {
    OdooComposeTemplate::saveFor('18', OdooComposeTemplate::defaultCompose('18'), '16-alpine');
    OdooComposeTemplate::saveFor('20', OdooComposeTemplate::defaultCompose('20'), '15-alpine');

    expect(OdooComposeTemplate::composeFor('18'))->toContain('odoo:18')
        ->and(OdooComposeTemplate::composeFor('18'))->toContain('postgres:16-alpine')
        ->and(OdooComposeTemplate::composeFor('20'))->toContain('odoo:20')
        ->and(OdooComposeTemplate::composeFor('20'))->toContain('postgres:15-alpine');
});

it('rejects a compose without odoo and a postgresql tag that is not a tag', function () {
    expect(fn () => OdooComposeTemplate::saveFor('18', "services:\n  ghost:\n    image: ghost:5\n", '16-alpine'))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => OdooComposeTemplate::saveFor('18', OdooComposeTemplate::defaultCompose('18'), '16 alpine'))
        ->toThrow(InvalidArgumentException::class);
});

it('hides odoo templates from someone who is not an instance admin', function () {
    $team = Team::factory()->create();
    $member = User::factory()->create();
    $member->teams()->attach($team, ['role' => 'owner']);
    $this->actingAs($member);
    session(['currentTeam' => $team]);

    Livewire::test(Odoo::class)->assertRedirect(route('dashboard'));
});
