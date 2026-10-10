<?php

use App\Domain\Odoo\OdooMail;
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
        ->assertHasErrors(['audience']);

    expect(GpshNotice::query()->where('title', 'Solo el owner')->exists())->toBeFalse();

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

it('records an upgrade failure once for the owner notice list', function () {
    $this->actingAs($this->owner);

    $first = GpshNotices::rememberUpgradeFailure('The image pull failed.');
    $second = GpshNotices::rememberUpgradeFailure('The image pull failed.');

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(GpshNotice::query()->where('body', 'The image pull failed.')->count())->toBe(1)
        ->and($first->audience)->toBe('owner');

    Livewire::test(GpshNoticeBell::class)->assertSee('The image pull failed.');

    $this->actingAs($this->client);
    Livewire::test(GpshNoticeBell::class)->assertDontSee('The image pull failed.');
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

it('removes notices older than the time set in the notification center', function () {
    $this->actingAs($this->owner);
    Livewire::test(Center::class)
        ->set('keepDays', 2)
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect(GpshNoticeSetting::current()->keep_days)->toBe(2);

    $old = GpshNotice::query()->create([
        'title' => 'Aviso viejo',
        'body' => 'Ya pasó.',
        'audience' => 'clients',
        'kind' => 'custom',
        'team_id' => $this->clientTeam->id,
    ]);
    $old->forceFill(['created_at' => now()->subDays(3)])->save();
    GpshNotice::query()->create([
        'title' => 'Aviso nuevo',
        'body' => 'Sigue aquí.',
        'audience' => 'clients',
        'kind' => 'custom',
        'team_id' => $this->clientTeam->id,
    ]);

    $this->actingAs($this->client);
    Livewire::test(GpshNoticeBell::class)
        ->assertDontSee('Aviso viejo')
        ->assertSee('Aviso nuevo');

    expect(GpshNotice::query()->where('title', 'Aviso viejo')->exists())->toBeFalse()
        ->and(GpshNotice::query()->where('title', 'Aviso nuevo')->exists())->toBeTrue();
});

it('puts the newest notice first and lets the owner delete them all from the center only', function () {
    $this->actingAs($this->owner);
    $older = GpshNotice::query()->create([
        'title' => 'Primero',
        'body' => 'Viejo.',
        'audience' => 'clients',
        'kind' => 'custom',
        'team_id' => $this->clientTeam->id,
    ]);
    $newer = GpshNotice::query()->create([
        'title' => 'Segundo',
        'body' => 'Nuevo.',
        'audience' => 'clients',
        'kind' => 'custom',
        'team_id' => $this->clientTeam->id,
    ]);

    Livewire::test(Center::class)
        ->assertSeeInOrder(['Segundo', 'Primero'])
        ->assertSee('Latest')
        ->call('deleteAll');

    expect(GpshNotice::query()->whereKey([$older->id, $newer->id])->exists())->toBeFalse();

    $again = GpshNotice::query()->create([
        'title' => 'Campana',
        'body' => 'Desde la campana.',
        'audience' => 'clients',
        'kind' => 'custom',
        'team_id' => $this->clientTeam->id,
    ]);

    Livewire::test(GpshNoticeBell::class)
        ->assertSee('Campana')
        ->assertSee('Delete all')
        ->call('deleteAll')
        ->assertDontSee('Campana');

    expect(GpshNotice::query()->whereKey($again->id)->exists())->toBeTrue()
        ->and($again->fresh()->reads()->where('user_id', $this->owner->id)->exists())->toBeTrue()
        ->and(file_get_contents(resource_path('views/livewire/gpsh-notice-bell.blade.php')))
        ->toContain('wire:click="deleteAll"')
        ->toContain('Clear all notices from this list?')
        ->toContain('inset-x-3');

    $this->actingAs($this->client);
    Livewire::test(GpshNoticeBell::class)
        ->assertSee('Campana')
        ->call('deleteAll')
        ->assertDontSee('Campana');

    expect(GpshNotice::query()->whereKey($again->id)->exists())->toBeTrue();
});

it('saves how long a new notice stays on screen', function () {
    $this->actingAs($this->owner);

    Livewire::test(Center::class)
        ->set('toast', false)
        ->set('toastSeconds', 12)
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect(GpshNoticeSetting::current()->toast)->toBeFalse()
        ->and(GpshNoticeSetting::current()->toast_seconds)->toBe(12);
});

it('allows twenty emails a day for one team and refuses a neutralized send path', function () {
    instanceSettings()->update(['odoo_mail_daily_limit' => 2]);
    $token = OdooMail::token((int) $this->clientTeam->id);

    $this->postJson('/gpsh/mail-quota', ['team_id' => $this->clientTeam->id, 'token' => 'nope'])->assertForbidden();
    $this->postJson('/gpsh/mail-quota', ['team_id' => $this->clientTeam->id, 'token' => $token])->assertOk()->assertJson(['allowed' => true]);
    $this->postJson('/gpsh/mail-quota', ['team_id' => $this->clientTeam->id, 'token' => $token])->assertOk()->assertJson(['allowed' => true]);
    $this->postJson('/gpsh/mail-quota', ['team_id' => $this->clientTeam->id, 'token' => $token])->assertOk()->assertJson(['allowed' => false]);
    $this->postJson('/gpsh/mail-quota', ['team_id' => $this->otherTeam->id, 'token' => OdooMail::token((int) $this->otherTeam->id)])
        ->assertOk()
        ->assertJson(['allowed' => true]);

    $command = file_get_contents(app_path('Support/OdooJupyter.php'));
    expect($command)->toContain('database.is_neutralized')
        ->and($command)->toContain('GPSH_MAIL_LIMIT')
        ->and(OdooMail::mailEnvironmentLines(instanceSettings(), (int) $this->clientTeam->id))->toContain('GPSH_MAIL_LIMIT="2"');
});
