<?php

use App\Actions\Service\StartService;
use App\Jobs\LaunchOdooProjectJob;
use App\Livewire\Project\AddEmpty;
use App\Livewire\Project\Resource\Index;
use App\Livewire\Project\Show;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Support\ServiceTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    seedOdooComposeVersions(['18', '20']);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->where('name', 'production')->first();

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = $this->server->standaloneDockers()->first()
        ?? StandaloneDocker::factory()->create([
            'server_id' => $this->server->id,
            'network' => 'coolify-test-'.uniqid(),
        ]);

    $this->composeDirectory = sys_get_temp_dir().'/coolify-service-catalog-launch-'.uniqid();
    mkdir($this->composeDirectory);
    config(['constants.services.compose_path' => $this->composeDirectory]);

    ServiceTemplateCatalog::create('demo-worker', "services:\n  app:\n    image: nginx:alpine\n", $this->user->id, [
        'display_name' => 'Demo Worker',
        'category' => 'Custom',
    ]);
});

afterEach(function () {
    if (is_dir($this->composeDirectory)) {
        foreach (glob($this->composeDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->composeDirectory);
    }
});

it('does not start containers when a catalog one-click service is created', function () {
    Bus::fake();

    $this->get(route('project.resource.create', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'type' => 'one-click-service-demo-worker',
        'destination' => $this->destination->uuid,
    ]))->assertRedirect();

    $service = Service::query()->where('environment_id', $this->environment->id)->first();

    expect($service)->not->toBeNull()
        ->and($service->service_type)->toBe('demo-worker');

    Bus::assertNotDispatched(StartService::class);
    Bus::assertNotDispatched(LaunchOdooProjectJob::class);
});

it('does not start containers when only the empty-environment service selection changes', function () {
    Bus::fake();

    Livewire::test(Index::class, [
        'project' => $this->project,
        'environment' => $this->environment,
        'allProjects' => collect([$this->project]),
        'allEnvironments' => collect([$this->environment]),
    ])
        ->set('selectedService', 'demo-worker')
        ->assertHasNoErrors();

    expect(Service::query()->where('environment_id', $this->environment->id)->count())->toBe(0);
    Bus::assertNotDispatched(StartService::class);
    Bus::assertNotDispatched(LaunchOdooProjectJob::class);
});

it('opens configuration without starting containers when Launch prepares a non-odoo service', function () {
    Bus::fake();

    Livewire::test(Index::class, [
        'project' => $this->project,
        'environment' => $this->environment,
        'allProjects' => collect([$this->project]),
        'allEnvironments' => collect([$this->environment]),
    ])
        ->set('selectedService', 'demo-worker')
        ->set('serverId', (string) $this->server->id)
        ->call('launchService')
        ->assertRedirect(route('project.service.configuration', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'service_uuid' => Service::query()->where('environment_id', $this->environment->id)->value('uuid'),
        ]));

    $service = Service::query()->where('environment_id', $this->environment->id)->first();

    expect($service)->not->toBeNull()
        ->and($service->service_type)->toBe('demo-worker');

    Bus::assertNotDispatched(StartService::class);
    Bus::assertNotDispatched(LaunchOdooProjectJob::class);
});

it('keeps Launch as the only action that creates services, with live service selection for version fields', function () {
    $blade = file_get_contents(resource_path('views/livewire/project/resource/index.blade.php'));

    expect($blade)->toContain('id="selectedService"')
        ->and($blade)->toContain('wire:click="launchService"')
        ->and($blade)->toContain("{{ __('New') }}")
        ->and($blade)->toContain('projectServerLocked')
        ->and($blade)->toContain('catalog-version-fields')
        ->and($blade)->toMatch('/id="selectedService"[^>]*\blive\b/');
});

it('asks for an Odoo version before enabling the profile on first catalog launch', function () {
    Bus::fake();

    Livewire::test(Index::class, [
        'project' => $this->project,
        'environment' => $this->environment,
        'allProjects' => collect([$this->project]),
        'allEnvironments' => collect([$this->environment]),
    ])
        ->assertSet('selectedService', 'odoo')
        ->assertSet('odooVersion', '')
        ->assertSee(__('Odoo version'))
        ->assertSee(__('This becomes the project version. Staging and other environments use the same Compose.'))
        ->assertDontSee(__('This project runs Odoo :version (chosen when you launched production). It cannot be changed later in this assistant or in project settings. Staging uses the same version.', ['version' => '20']))
        ->set('odooVersion', '20')
        ->set('serverId', (string) $this->server->id)
        ->call('launchService')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect($this->project->fresh()->odooProfile?->odoo_version)->toBe('20');
    Bus::assertDispatched(LaunchOdooProjectJob::class);
});

it('does not hardcode Odoo 18 when launching without a version choice in the trait', function () {
    $source = file_get_contents(app_path('Livewire/Project/Concerns/LaunchesCatalogService.php'));

    expect($source)
        ->toContain('validateCatalogOdooVersion')
        ->toContain('enableOdoo($this->odooVersion)')
        ->not->toContain("enableOdoo('18')");
});

it('reuses the project server when launching another catalog service', function () {
    Bus::fake();

    $existing = Service::factory()->create([
        'name' => 'odoo-seed',
        'environment_id' => $this->environment->id,
        'service_type' => 'odoo',
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:18\n",
    ]);

    Livewire::test(Index::class, [
        'project' => $this->project->fresh(),
        'environment' => $this->environment->fresh(),
        'allProjects' => collect([$this->project]),
        'allEnvironments' => collect([$this->environment]),
    ])
        ->assertSet('serverId', (string) $this->server->id)
        ->set('selectedService', 'demo-worker')
        ->call('launchService')
        ->assertRedirect();

    $created = Service::query()
        ->where('environment_id', $this->environment->id)
        ->where('service_type', 'demo-worker')
        ->first();

    expect($created)->not->toBeNull()
        ->and((int) $created->server_id)->toBe((int) $this->server->id)
        ->and((int) $created->destination_id)->toBe((int) $this->destination->id)
        ->and($existing->fresh())->not->toBeNull();

    Bus::assertNotDispatched(StartService::class);
});

it('asks for confirmation before removing a github collaborator on the service panel', function () {
    $blade = file_get_contents(resource_path('views/livewire/project/service/configuration.blade.php'));

    expect($blade)
        ->toContain('wire:click="removeGithubUser(')
        ->toContain('wire:confirm=')
        ->toContain('inviteGithubUser')
        ->toContain("{{ __('Collaborators') }}");

    expect(file_get_contents(resource_path('views/livewire/project/edit.blade.php')))
        ->toContain('Open GitHub on the service')
        ->not->toContain('wire:click="removeGithubUser(');
});

it('lets the new-project form pick a configured Odoo version without inventing 18', function () {
    Livewire::test(AddEmpty::class)
        ->assertSet('odooVersion', '')
        ->set('service', 'odoo')
        ->assertSee(__('Odoo version'))
        ->assertSee(__('This becomes the project version. Staging and other environments use the same Compose.'));
});

it('shows the immutable version warning on the GitHub launch wizard', function () {
    $blade = file_get_contents(resource_path('views/livewire/project/service/configuration.blade.php'));

    expect($blade)
        ->toContain('awaitingRepositoryChoice')
        ->toContain('chosen when you launched production');
});

it('creates a non-odoo service from the new-project form without starting containers', function () {
    Bus::fake();

    Livewire::test(AddEmpty::class)
        ->set('name', 'Demo project')
        ->set('service', 'demo-worker')
        ->set('serverId', (string) $this->server->id)
        ->call('submit')
        ->assertRedirect();

    $project = Project::query()->where('name', 'Demo project')->first();
    expect($project)->not->toBeNull();

    $service = $project->environments()->where('name', 'production')->first()?->services()->first();
    expect($service)->not->toBeNull()
        ->and($service->service_type)->toBe('demo-worker');

    Bus::assertNotDispatched(StartService::class);
    Bus::assertNotDispatched(LaunchOdooProjectJob::class);
});

it('fills empty production on the first project New launch', function () {
    Bus::fake();

    expect($this->environment->isEmpty())->toBeTrue();

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->set('selectedService', 'demo-worker')
        ->set('serverId', (string) $this->server->id)
        ->call('launchService')
        ->assertRedirect(route('project.service.configuration', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'service_uuid' => Service::query()->where('environment_id', $this->environment->id)->value('uuid'),
        ]));

    expect(Service::query()->where('environment_id', $this->environment->id)->where('service_type', 'demo-worker')->exists())->toBeTrue()
        ->and($this->project->fresh()->environments()->count())->toBe(1);

    Bus::assertNotDispatched(StartService::class);
    Bus::assertNotDispatched(LaunchOdooProjectJob::class);
});

it('creates a new staging environment when New launches another catalog service', function () {
    Bus::fake();

    Service::factory()->create([
        'name' => 'seed-service',
        'environment_id' => $this->environment->id,
        'service_type' => 'odoo',
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:18\n",
    ]);

    $before = $this->project->environments()->count();

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->set('selectedService', 'demo-worker')
        ->set('serverId', (string) $this->server->id)
        ->call('launchService')
        ->assertRedirect();

    $created = Service::query()->where('service_type', 'demo-worker')->first();
    $newEnvironment = $created?->environment;

    expect($created)->not->toBeNull()
        ->and($this->project->fresh()->environments()->count())->toBe($before + 1)
        ->and($newEnvironment)->not->toBeNull()
        ->and($newEnvironment->isStaging())->toBeTrue()
        ->and((int) $newEnvironment->id)->not->toBe((int) $this->environment->id)
        ->and(Service::query()->where('environment_id', $this->environment->id)->where('service_type', 'demo-worker')->exists())->toBeFalse();

    Bus::assertNotDispatched(StartService::class);
    Bus::assertNotDispatched(LaunchOdooProjectJob::class);
});
