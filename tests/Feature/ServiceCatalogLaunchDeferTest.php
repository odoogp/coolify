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
use App\Models\ServiceApplication;
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
        ->assertSee(__('Choose the Compose for this environment. Other environments can use a different Odoo version.'))
        ->assertDontSee(__('Production set this project to Odoo :version. New environments reuse that version.', ['version' => '20']))
        ->set('odooVersion', '20')
        ->set('serverId', (string) $this->server->id)
        ->call('launchService')
        ->assertHasNoErrors()
        ->assertRedirect(route('project.service.configuration', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'service_uuid' => Service::query()->where('environment_id', $this->environment->id)->value('uuid'),
        ]));

    expect($this->project->fresh()->odooProfile?->odoo_version)->toBe('20');
    Bus::assertNotDispatched(LaunchOdooProjectJob::class);
    Bus::assertNotDispatched(StartService::class);
});

it('lets a later Odoo launch pick a different Compose than the project profile', function () {
    Bus::fake();
    $this->project->enableOdoo('20');
    Service::factory()->create([
        'name' => 'odoo-seed',
        'environment_id' => $this->environment->id,
        'service_type' => 'odoo',
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->assertSee(__('Odoo version'))
        ->assertDontSee(__('Production set this project to Odoo :version. New environments reuse that version.', ['version' => '20']))
        ->set('environmentRole', 'staging')
        ->set('environmentName', 'cliente-staging')
        ->set('selectedService', 'odoo')
        ->set('odooVersion', '18')
        ->set('serverId', (string) $this->server->id)
        ->call('launchService')
        ->assertHasNoErrors()
        ->assertRedirect();

    $staging = $this->project->fresh()->environments()->where('name', 'cliente-staging')->first();
    expect($staging)->not->toBeNull()
        ->and($staging->isStaging())->toBeTrue();

    $created = $staging->services()->where('service_type', 'odoo')->first();
    expect($created)->not->toBeNull()
        ->and($created->docker_compose_raw)->toContain('odoo:18')
        ->and((string) $staging->odooBranch?->odoo_version)->toBe('18')
        // Never invent git_branch from the environment name (would clone a missing tip).
        ->and((string) $staging->odooBranch?->git_branch)->toBe('')
        ->and((string) $this->project->fresh()->odooProfile?->odoo_version)->toBe('20');

    Bus::assertNotDispatched(LaunchOdooProjectJob::class);
    Bus::assertNotDispatched(StartService::class);
});

it('does not force the project repository when catalog-launching another Odoo', function () {
    Bus::fake();
    $this->project->enableOdoo('20');
    Service::factory()->create([
        'name' => 'odoo-seed',
        'environment_id' => $this->environment->id,
        'service_type' => 'odoo',
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);
    $this->project->odooProfile->update([
        'git_repository' => 'acme/odoogp20',
        'repository_id' => 99,
    ]);

    $component = Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->assertSee(__('Environment name'))
        ->set('environmentRole', 'production')
        ->set('environmentName', 'odoo-19')
        ->set('selectedService', 'odoo')
        ->set('odooVersion', '18')
        ->set('serverId', (string) $this->server->id)
        ->call('launchService')
        ->assertHasNoErrors();

    $redirect = (string) ($component->effects['redirect'] ?? '');
    expect($redirect)->not->toContain('odooPanel=github')
        ->and($redirect)->not->toContain('launch=choose');

    $env = $this->project->fresh()->environments()->where('name', 'odoo-19')->first();
    expect($env)->not->toBeNull()
        ->and($env->isProduction())->toBeTrue()
        ->and((string) $env->odooBranch?->git_branch)->toBe('')
        ->and(file_get_contents(app_path('Livewire/Project/Concerns/LaunchesCatalogService.php')))
        ->not->toContain("'odooPanel' => 'github'")
        ->and(file_get_contents(app_path('Support/OdooGit.php')))
        ->not->toContain('odooBranch?->git_branch ?: $service->environment?->name');
});

it('refuses a duplicate environment name on catalog New', function () {
    Bus::fake();
    seedOdooComposeVersions(['18', '20']);

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->set('environmentRole', 'staging')
        ->set('environmentName', 'production')
        ->set('selectedService', 'odoo')
        ->set('odooVersion', '20')
        ->set('serverId', (string) $this->server->id)
        ->call('launchService')
        ->assertHasErrors(['environmentName'])
        ->assertNoRedirect();
});

it('starts Odoo containers only when Launch without GitHub is explicit', function () {
    Bus::fake();

    Livewire::test(Index::class, [
        'project' => $this->project,
        'environment' => $this->environment,
        'allProjects' => collect([$this->project]),
        'allEnvironments' => collect([$this->environment]),
    ])
        ->set('odooVersion', '20')
        ->set('serverId', (string) $this->server->id)
        ->set('skipGithubChoice', true)
        ->call('launchService')
        ->assertHasNoErrors()
        ->assertRedirect(route('project.show', ['project_uuid' => $this->project->uuid]));

    Bus::assertDispatched(LaunchOdooProjectJob::class);
});

it('does not hardcode Odoo 18 when launching without a version choice in the trait', function () {
    $source = file_get_contents(app_path('Livewire/Project/Concerns/LaunchesCatalogService.php'));

    expect($source)
        ->toContain('validateCatalogOdooVersion')
        ->toContain('enableOdoo($this->odooVersion)')
        ->toContain('$this->odooVersion !== \'\' ? $this->odooVersion')
        ->not->toContain("enableOdoo('18')")
        ->not->toContain('$project->odooProfile === null && $this->catalogVersionOptions');
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

    // Occupied environments redirect to the primary service unless ?catalog=1.
    Livewire::withQueryParams(['catalog' => '1'])
        ->test(Index::class, [
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
        ->assertSee(__('Choose the Compose for this environment. Other environments can use a different Odoo version.'));
});

it('shows the environment version note on the GitHub launch wizard', function () {
    $blade = file_get_contents(resource_path('views/livewire/project/service/configuration.blade.php'));

    expect($blade)
        ->toContain('awaitingRepositoryChoice')
        ->toContain('This environment installs Odoo :version')
        ->not->toContain('chosen when you launched production');
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

it('creates an Odoo project from the new-project form without starting containers', function () {
    Bus::fake();

    Livewire::test(AddEmpty::class)
        ->set('name', 'Odoo project')
        ->set('service', 'odoo')
        ->set('odooVersion', '20')
        ->set('connectGithub', false)
        ->set('serverId', (string) $this->server->id)
        ->call('submit')
        ->assertRedirect();

    $project = Project::query()->where('name', 'Odoo project')->first();
    $service = $project?->environments()->where('name', 'production')->first()?->services()->first();

    expect($project)->not->toBeNull()
        ->and($project->odooProfile?->odoo_version)->toBe('20')
        ->and($service)->not->toBeNull()
        ->and($service->service_type)->toBe('odoo');

    Bus::assertNotDispatched(StartService::class);
    Bus::assertNotDispatched(LaunchOdooProjectJob::class);
});

it('fills empty production on the first project New launch', function () {
    Bus::fake();

    expect($this->environment->isEmpty())->toBeTrue();

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->assertSet('environmentName', 'production')
        ->set('selectedService', 'demo-worker')
        ->set('environmentRole', 'production')
        ->set('environmentName', 'production')
        ->set('serverId', (string) $this->server->id)
        ->call('launchService')
        ->assertRedirect(route('project.service.configuration', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'service_uuid' => Service::query()->where('environment_id', $this->environment->id)->value('uuid'),
        ]));

    expect(Service::query()->where('environment_id', $this->environment->id)->where('service_type', 'demo-worker')->exists())->toBeTrue()
        ->and($this->project->fresh()->environments()->count())->toBe(1)
        ->and($this->environment->fresh()->name)->toBe('production');

    Bus::assertNotDispatched(StartService::class);
    Bus::assertNotDispatched(LaunchOdooProjectJob::class);
});

it('creates a new production environment when New launches a different catalog service', function () {
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
        ->set('environmentRole', 'production')
        ->set('environmentName', 'demo-worker')
        ->set('serverId', (string) $this->server->id)
        ->call('launchService')
        ->assertRedirect();

    $created = Service::query()->where('service_type', 'demo-worker')->first();
    $newEnvironment = $created?->environment;

    expect($created)->not->toBeNull()
        ->and($this->project->fresh()->environments()->count())->toBe($before + 1)
        ->and($newEnvironment)->not->toBeNull()
        ->and($newEnvironment->isProduction())->toBeTrue()
        ->and($newEnvironment->name)->toBe('demo-worker')
        ->and((int) $newEnvironment->id)->not->toBe((int) $this->environment->id)
        ->and(Service::query()->where('environment_id', $this->environment->id)->where('service_type', 'demo-worker')->exists())->toBeFalse();

    Bus::assertNotDispatched(StartService::class);
    Bus::assertNotDispatched(LaunchOdooProjectJob::class);
});

it('creates staging when New launches a second instance of the same catalog service', function () {
    Bus::fake();

    Service::factory()->create([
        'name' => 'seed-worker',
        'environment_id' => $this->environment->id,
        'service_type' => 'demo-worker',
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  worker:\n    image: alpine\n",
    ]);

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->set('selectedService', 'demo-worker')
        ->set('environmentRole', 'staging')
        ->set('environmentName', 'cliente-b')
        ->set('serverId', (string) $this->server->id)
        ->call('launchService')
        ->assertRedirect();

    $created = Service::query()
        ->where('service_type', 'demo-worker')
        ->where('environment_id', '!=', $this->environment->id)
        ->first();

    expect($created)->not->toBeNull()
        ->and($created->environment?->isStaging())->toBeTrue()
        ->and($created->environment?->name)->toBe('cliente-b');

    Bus::assertNotDispatched(StartService::class);
});

it('exposes open logs and terminal shortcuts for non-odoo catalog environments', function () {
    $service = Service::factory()->create([
        'name' => 'obsidian',
        'environment_id' => $this->environment->id,
        'service_type' => 'obsidian',
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  app:\n    image: nginx:alpine\n",
        'status' => 'running:healthy',
    ]);
    ServiceApplication::query()->create([
        'name' => 'app',
        'service_id' => $service->id,
        'fqdn' => 'https://obsidian.example.test',
    ]);

    expect($service->fresh()->publicHttpsUrl())->toBe('https://obsidian.example.test')
        ->and($service->fresh()->canOpenPublicSite())->toBeTrue()
        ->and($service->supportsOdooJupyter())->toBeFalse();

    $payload = Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->get('environmentPayload');
    $row = collect($payload)->firstWhere('uuid', $this->environment->uuid);

    expect($row)->not->toBeNull()
        ->and($row['isOdooService'])->toBeFalse()
        ->and($row['enterHref'])->toBe('https://obsidian.example.test')
        ->and($row['enterLabel'])->toBe(__('Open'))
        ->and($row['logsHref'])->toContain('/logs')
        ->and($row['logsHref'])->not->toContain('only=odoo')
        ->and($row['jupyterHref'])->toBeNull()
        ->and($row['monitorHref'])->toBeNull()
        ->and($row['terminalHref'])->not->toBeNull()
        ->and($row['terminalHref'])->not->toContain('shell=odoo');
});

it('keeps catalog service heading open and logs controls in the blade', function () {
    $heading = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));
    $open = file_get_contents(resource_path('views/livewire/project/odoo-open.blade.php'));

    expect($heading)
        ->toContain('$serviceOpenUrl')
        ->toContain('$serviceLogsUrl')
        ->toContain('canOpenPublicSite()')
        ->and($open)
        ->toContain('environment.isOdooService')
        ->toContain('!environment.isOdooService');
});
