<?php

use App\Livewire\Settings\Odoo;
use App\Models\InstanceSettings;
use App\Models\OdooComposeTemplate;
use App\Models\Team;
use App\Models\User;
use App\Domain\Odoo\OdooVersion;
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

it('lists only saved odoo templates and opens the editor on New', function () {
    Livewire::test(Odoo::class)
        ->assertSee('Odoo templates')
        ->assertSee(__('No Odoo templates yet.'))
        ->assertSet('showTemplateEditor', false)
        ->call('newTemplate')
        ->assertSet('showTemplateEditor', true)
        ->assertSet('editingExistingTemplate', false)
        ->assertSee(__('New Odoo template'));
});

it('saves a new template and returns it on the list', function () {
    $component = Livewire::test(Odoo::class)
        ->call('newTemplate')
        ->set('version', '18')
        ->set('postgresVersion', '17-alpine')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showTemplateEditor', false)
        ->assertSee('18')
        ->assertSee('17-alpine')
        ->assertDontSee(__('No Odoo templates yet.'));

    $saved = OdooComposeTemplate::query()->where('version', '18')->first();

    expect($saved)->not->toBeNull()
        ->and($saved->compose)->toContain('image: odoo:18')
        ->and($saved->compose)->toContain('image: postgres:17-alpine')
        ->and($saved->compose)->not->toContain('postgres:16-alpine')
        ->and(OdooComposeTemplate::composeFor('20'))->toBeNull()
        ->and(OdooVersion::current(OdooComposeTemplate::defaultCompose('20')))->toBe('20');

    $component->call('editTemplate', '18')
        ->assertSet('showTemplateEditor', true)
        ->assertSet('editingExistingTemplate', true)
        ->assertSet('version', '18')
        ->assertSet('postgresVersion', '17-alpine')
        ->assertSee(__('Edit Odoo template'));
});

it('keeps each odoo version template separate', function () {
    OdooComposeTemplate::saveFor('18', OdooComposeTemplate::defaultCompose('18'), '16-alpine');
    OdooComposeTemplate::saveFor('20', OdooComposeTemplate::defaultCompose('20'), '15-alpine');

    expect(OdooComposeTemplate::composeFor('18'))->toContain('odoo:18')
        ->and(OdooComposeTemplate::composeFor('18'))->toContain('postgres:16-alpine')
        ->and(OdooComposeTemplate::composeFor('20'))->toContain('odoo:20')
        ->and(OdooComposeTemplate::composeFor('20'))->toContain('postgres:15-alpine');

    Livewire::test(Odoo::class)
        ->assertSee('18')
        ->assertSee('20')
        ->assertSee('16-alpine')
        ->assertSee('15-alpine')
        ->assertDontSee(__('No Odoo templates yet.'));
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
