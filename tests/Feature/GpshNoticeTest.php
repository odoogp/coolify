<?php

use App\Livewire\GpshNoticeBell;
use App\Livewire\Notifications\Center;
use App\Models\Environment;
use App\Models\GpshNotice;
use App\Models\GpshNoticeSetting;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Team;
use App\Models\User;
use App\Support\GpshNotices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\View\ViewException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->root = Team::factory()->create(['id' => 0, 'name' => 'Root', 'personal_team' => false]);
    $this->owner = User::factory()->create();
    $this->owner->teams()->attach($this->root, ['role' => 'owner']);

    $this->clientTeam = Team::factory()->create(['name' => 'Acme']);
    $this->client = User::factory()->create();
    $this->client->teams()->attach($this->clientTeam, ['role' => 'admin']);

    $this->otherTeam = Team::factory()->create(['name' => 'Other']);
    $this->other = User::factory()->create();
    $this->other->teams()->attach($this->otherTeam, ['role' => 'owner']);
});

it('lets the owner send a notice that only the chosen client sees', function () {
    $this->actingAs($this->owner);

    Livewire::test(Center::class)
        ->set('title', 'Vence mañana')
        ->set('body', 'La instancia se elimina el viernes.')
        ->set('audience', 'clients')
        ->set('kind', 'expiration')
        ->set('teamId', (string) $this->clientTeam->id)
        ->call('send')
        ->assertHasNoErrors()
        ->assertSee('Vence mañana')
        ->assertSee('For clients');

    $this->actingAs($this->client);
    Livewire::test(GpshNoticeBell::class)->assertSee('Vence mañana');

    $this->actingAs($this->other);
    Livewire::test(GpshNoticeBell::class)->assertDontSee('Vence mañana');
});

it('hides owner notices from clients and blocks a kind that is turned off', function () {
    $this->actingAs($this->owner);

    Livewire::test(Center::class)
        ->set('title', 'Solo el owner')
        ->set('body', 'Nota interna.')
        ->set('audience', 'owner')
        ->set('kind', 'custom')
        ->call('send')
        ->assertHasNoErrors();

    $this->actingAs($this->client);
    Livewire::test(GpshNoticeBell::class)->assertDontSee('Solo el owner');
    expect(isInstanceOwner())->toBeFalse();
    expect(fn () => Livewire::test(Center::class))->toThrow(ViewException::class);

    $this->actingAs($this->owner);
    Livewire::test(Center::class)
        ->set('showCustom', false)
        ->call('saveSettings');

    expect(GpshNoticeSetting::current()->custom)->toBeFalse();

    Livewire::test(Center::class)
        ->set('title', 'No debe salir')
        ->set('body', 'Apagado.')
        ->set('kind', 'custom')
        ->call('send');

    expect(GpshNotice::query()->where('title', 'No debe salir')->exists())->toBeFalse();
});

it('announces when the instance is up and when it can be opened', function () {
    $project = Project::factory()->create(['name' => 'Mi Empresa', 'team_id' => $this->clientTeam->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['name' => 'production', 'project_id' => $project->id]);
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);

    $mounted = false;
    $accessible = false;
    GpshNotices::watch($service, 'The service containers are running.', 'in_progress', $mounted, $accessible);
    GpshNotices::watch($service, 'The service containers are running.', 'finished', $mounted, $accessible);

    $mounted = GpshNotice::query()->where('kind', 'mounted')->first();
    $accessible = GpshNotice::query()->where('kind', 'accessible')->first();

    expect(GpshNotice::query()->where('kind', 'mounted')->count())->toBe(1)
        ->and(GpshNotice::query()->where('kind', 'accessible')->count())->toBe(1)
        ->and($mounted->team_id)->toBe($this->clientTeam->id)
        ->and($mounted->href())->toContain($project->uuid)
        ->and($mounted->href())->toContain('environment='.$environment->uuid)
        ->and($accessible->href())->toContain($project->uuid);

    ServiceApplication::query()->create([
        'service_id' => $service->id,
        'name' => 'odoo',
        'image' => 'odoo:20',
        'fqdn' => 'https://odoo.example.test',
        'status' => 'running:healthy',
    ]);

    expect($accessible->fresh()->href())->toStartWith('https://odoo.example.test');

    $this->actingAs($this->client);
    Livewire::test(GpshNoticeBell::class)
        ->assertSee('Odoo is up')
        ->assertSee('Odoo is accessible')
        ->call('openNotice', $mounted->id)
        ->assertRedirect($mounted->href());
});
