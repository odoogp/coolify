<?php

use App\Domain\Odoo\OdooMail;
use App\Jobs\NotifyGetOdooServerInvoicesJob;
use App\Livewire\GpshNoticeBell;
use App\Livewire\Notifications\Center;
use App\Livewire\Upgrade;
use App\Models\Environment;
use App\Models\GetOdooServerOffer;
use App\Models\GpshNotice;
use App\Models\GpshNoticeSetting;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Team;
use App\Models\User;
use App\Support\GpshNotices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

it('announces an upgrade start once for owners only via the notice bell', function () {
    $this->actingAs($this->owner);

    $first = GpshNotices::announceUpgradeStarted('4.0.0-beta.999');
    $second = GpshNotices::announceUpgradeStarted('4.0.0-beta.999');

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and($first->audience)->toBe('owner')
        ->and($first->team_id)->toBeNull()
        ->and($first->title)->toBe(__('Update in progress'))
        ->and($first->body)->toContain('4.0.0-beta.999')
        ->and(GpshNotice::query()->where('title', __('Update in progress'))->count())->toBe(1);

    Livewire::test(GpshNoticeBell::class)->assertSee(__('Update in progress'));

    $this->actingAs($this->client);
    Livewire::test(GpshNoticeBell::class)->assertDontSee(__('Update in progress'));
});

it('toasts when an instance admin starts an upgrade', function () {
    $this->actingAs($this->owner);
    InstanceSettings::query()->whereKey(0)->update(['new_version_available' => true]);
    config(['constants.coolify.version' => '4.0.0-beta.998']);
    Cache::put('coolify:versions:all', [
        'coolify' => ['v4' => ['version' => '4.0.0-beta.999']],
    ], 3600);

    Livewire::test(Upgrade::class)
        ->call('upgrade')
        ->assertDispatched('gpsh-toast');

    expect(GpshNotice::query()->where('title', __('Update in progress'))->exists())->toBeTrue()
        ->and(file_get_contents(app_path('Actions/Server/UpdateCoolify.php')))
        ->toContain('announceUpgradeStarted');
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

it('notifies when an odoo restart starts and watches until it can be opened', function () {
    $project = Project::factory()->create(['name' => 'Mi Empresa', 'team_id' => $this->clientTeam->id]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['name' => 'production', 'project_id' => $project->id]);
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);

    $this->actingAs($this->client);
    $restarting = GpshNotices::announceRestarting($service);

    expect($restarting)->not->toBeNull()
        ->and($restarting->kind)->toBe('custom')
        ->and($restarting->title)->toBe(__('Odoo is restarting'))
        ->and($restarting->body)->toContain('Mi Empresa')
        ->and($restarting->team_id)->toBe($this->clientTeam->id);

    Livewire::test(GpshNoticeBell::class)->assertSee(__('Odoo is restarting'));

    $heading = file_get_contents(app_path('Livewire/Project/Service/Heading.php'));
    expect($heading)
        ->toContain('announceRestarting')
        ->toContain('WatchOdooServiceStartJob')
        ->toContain('The instance started restarting.');
    expect(file_get_contents(app_path('Jobs/WatchOdooServiceStartJob.php')))
        ->toContain('GpshNotices::watchActivity');
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
        ->toContain('inset-x-3')
        ->toContain('onToast($event)')
        ->toMatch('/wire:poll\.15s[^>]*>\s*<div[^>]*x-data=/s');

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

it('notifies the client team about an overdue server invoice with a billing link', function () {
    Cache::flush();
    GpshNoticeSetting::current();

    $offer = GetOdooServerOffer::query()->create([
        'hetzner_type_id' => 22,
        'name' => 'cx22',
        'description' => 'CX22',
        'cores' => 2,
        'memory' => 4,
        'disk' => 40,
        'architecture' => 'x86',
        'monthly_price' => 10,
        'location' => 'fsn1',
        'locations' => [['location' => 'fsn1', 'monthly' => 10]],
        'available_for_admins' => true,
        'in_stock' => true,
    ]);
    $key = PrivateKey::factory()->create(['team_id' => $this->clientTeam->id]);
    $server = Server::factory()->create([
        'name' => 'cliente-factura',
        'team_id' => $this->clientTeam->id,
        'private_key_id' => $key->id,
        'getodoo_offer_id' => $offer->id,
        'getodoo_monthly_price' => 10,
        'getodoo_paid_until' => now()->subDay()->toDateString(),
    ]);

    $notice = GpshNotices::announceServerInvoice($server, 'overdue');

    expect($notice)->toBeInstanceOf(GpshNotice::class)
        ->and($notice->href())->toBe(route('server.billing'))
        ->and($notice->actionLabel())->toBe(__('View my invoices'));

    $this->actingAs($this->client);
    Livewire::test(GpshNoticeBell::class)
        ->assertSee(__('Invoice overdue'))
        ->assertSee(__('View my invoices'))
        ->call('openNotice', $notice->id)
        ->assertRedirect(route('server.billing'));
});

it('sends due-soon and overdue invoice notices from the daily job', function () {
    Cache::flush();
    GpshNoticeSetting::current();

    $offer = GetOdooServerOffer::query()->create([
        'hetzner_type_id' => 22,
        'name' => 'cx22',
        'description' => 'CX22',
        'cores' => 2,
        'memory' => 4,
        'disk' => 40,
        'architecture' => 'x86',
        'monthly_price' => 10,
        'location' => 'fsn1',
        'locations' => [['location' => 'fsn1', 'monthly' => 10]],
        'available_for_admins' => true,
        'in_stock' => true,
    ]);
    $key = PrivateKey::factory()->create(['team_id' => $this->clientTeam->id]);
    Server::factory()->create([
        'name' => 'por-vencer',
        'team_id' => $this->clientTeam->id,
        'private_key_id' => $key->id,
        'getodoo_offer_id' => $offer->id,
        'getodoo_monthly_price' => 10,
        'getodoo_paid_until' => now()->addDays(3)->toDateString(),
    ]);
    Server::factory()->create([
        'name' => 'vencida',
        'team_id' => $this->clientTeam->id,
        'private_key_id' => $key->id,
        'getodoo_offer_id' => $offer->id,
        'getodoo_monthly_price' => 10,
        'getodoo_paid_until' => now()->subDays(2)->toDateString(),
    ]);

    (new NotifyGetOdooServerInvoicesJob)->handle();

    expect(GpshNotice::query()->where('title', __('Invoice due soon'))->count())->toBe(1)
        ->and(GpshNotice::query()->where('title', __('Invoice overdue'))->count())->toBe(1);
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
