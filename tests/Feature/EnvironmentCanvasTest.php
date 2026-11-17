<?php

use App\Actions\Service\DeleteService;
use App\Actions\Service\StartService;
use App\Livewire\Project\Environment\Canvas;
use App\Livewire\Project\Show;
use App\Models\EnvironmentCanvasLayout;
use App\Models\EnvironmentStackTemplate;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\OdooEnvironmentBranch;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Support\EnvironmentCanvasGraph;
use App\Support\EnvironmentStackTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    seedOdooComposeVersions();

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Canvas Co']);
    $this->project->enableOdoo('20', 0, false);
    $this->environment = $this->project->environments()->where('name', 'production')->firstOrFail();

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $this->server->id]);

    $this->service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'name' => 'odoo-web',
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n    volumes:\n      - odoo-extra-addons:/mnt/extra-addons\nvolumes:\n  odoo-extra-addons:\n",
    ]);

    $this->odooApp = ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'service_id' => $this->service->id,
        'name' => 'odoo',
        'human_name' => 'Odoo',
        'image' => 'odoo:20',
        'fqdn' => 'https://erp.example.test',
    ]);

    $this->volume = LocalPersistentVolume::create([
        'name' => 'odoo-extra-addons',
        'mount_path' => '/mnt/extra-addons',
        'resource_id' => $this->odooApp->id,
        'resource_type' => $this->odooApp->getMorphClass(),
    ]);
});

it('builds one node per stack unit and always shows a connectable github node for odoo', function () {
    $graph = EnvironmentCanvasGraph::build($this->environment->fresh());

    $ids = collect($graph['nodes'])->pluck('id');
    $odoo = collect($graph['nodes'])->first(fn (array $n): bool => $n['id'] === 'stack-app:'.$this->odooApp->uuid);
    $github = collect($graph['nodes'])->first(fn (array $n): bool => $n['id'] === 'github:'.$this->service->uuid);

    expect($ids->contains('service:'.$this->service->uuid))->toBeFalse()
        ->and($odoo)->not->toBeNull()
        ->and($odoo['kind'])->toBe('odoo')
        ->and($odoo['panels'])->toContain('logs')
        ->and($odoo['panels'])->toContain('config')
        ->and($odoo['config_href'])->toContain($this->service->uuid)
        ->and($odoo['container'])->toBe('odoo-'.$this->service->uuid)
        ->and($github)->not->toBeNull()
        ->and($github['github']['linked'])->toBeFalse()
        ->and($github['status_label'])->toBe(__('Connect'))
        ->and(collect($graph['nodes'])->contains(fn (array $n): bool => $n['kind'] === 'domain'))->toBeFalse()
        ->and(collect($graph['nodes'])->contains(fn (array $n): bool => $n['kind'] === 'volume' && ! ($n['orphan'] ?? false)))->toBeFalse()
        ->and(collect($odoo['domains'] ?? [])->pluck('label')->implode(','))->toContain('erp.example.test')
        ->and(collect($odoo['volumes'] ?? [])->pluck('label'))->toContain('odoo-extra-addons')
        ->and($graph['meta']['count'])->toBeGreaterThanOrEqual(1);
});

it('marks the github node linked after the environment saves a repository and app', function () {
    $app = GithubApp::create([
        'name' => 'Canvas GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 4242,
        'installation_id' => 4242,
        'team_id' => $this->team->id,
        'is_public' => false,
    ]);
    $this->project->odooProfile?->update(['github_app_id' => $app->id]);

    OdooEnvironmentBranch::query()->updateOrCreate(
        ['environment_id' => $this->environment->id],
        [
            'git_repository' => 'acme/erp',
            'git_branch' => 'main',
            'status' => 'idle',
        ],
    );

    $graph = EnvironmentCanvasGraph::build($this->environment->fresh(['odooBranch', 'project.odooProfile', 'services.applications']));
    $github = collect($graph['nodes'])->first(fn (array $n): bool => $n['id'] === 'github:'.$this->service->uuid);

    expect($github)->not->toBeNull()
        ->and($github['kind'])->toBe('github')
        ->and($github['github']['linked'])->toBeTrue()
        ->and($github['status_label'])->toBe(__('Linked'))
        ->and(collect($graph['edges'])->contains(
            fn (array $e): bool => $e['from'] === 'github:'.$this->service->uuid && $e['to'] === 'stack-app:'.$this->odooApp->uuid
        ))->toBeTrue();
});

it('marks incomplete github when the profile has a repo without an app', function () {
    $this->project->odooProfile?->update([
        'git_repository' => 'acme/erp',
        'github_app_id' => null,
        'repository_id' => 99,
    ]);

    $graph = EnvironmentCanvasGraph::build($this->environment->fresh(['odooBranch', 'project.odooProfile', 'services.applications']));
    $github = collect($graph['nodes'])->first(fn (array $n): bool => $n['id'] === 'github:'.$this->service->uuid);

    expect($github)->not->toBeNull()
        ->and($github['github']['incomplete'])->toBeTrue()
        ->and($github['status_label'])->toBe(__('Needs setup'));
});

it('keeps domains and volumes on the service summary for the inspector', function () {
    $graph = EnvironmentCanvasGraph::build($this->environment->fresh());
    $odoo = collect($graph['nodes'])->first(fn (array $n): bool => $n['id'] === 'stack-app:'.$this->odooApp->uuid);

    expect($odoo)->not->toBeNull()
        ->and(collect($graph['nodes'])->where('kind', 'domain'))->toBeEmpty()
        ->and(collect($graph['nodes'])->filter(fn (array $n): bool => ($n['kind'] ?? null) === 'volume' && ! ($n['orphan'] ?? false)))->toBeEmpty()
        ->and($odoo['domains'] ?? [])->not->toBeEmpty()
        ->and(collect($odoo['domains'])->first()['config_href'] ?? null)->toContain('/domains')
        ->and($odoo['volumes'] ?? [])->not->toBeEmpty()
        ->and(collect($odoo['volumes'])->pluck('label'))->toContain('odoo-extra-addons');
});

it('lists domains and volumes in the service side inspector', function () {
    $graph = EnvironmentCanvasGraph::build($this->environment->fresh());
    $odoo = collect($graph['nodes'])->first(fn (array $n): bool => $n['id'] === 'stack-app:'.$this->odooApp->uuid);

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('openInspector', $odoo['id'], 'details')
        ->assertSet('inspector', 'node')
        ->assertSet('inspectorTab', 'details')
        ->assertSee(__('Domains'))
        ->assertSee('https://erp.example.test')
        ->assertSee(__('Configure domains'))
        ->assertSee(__('Volumes'))
        ->assertSee('odoo-extra-addons')
        ->assertSee(__('Open storage configuration'));
});

it('still opens orphan volume settings from the canvas', function () {
    $orphan = LocalPersistentVolume::create([
        'name' => 'kept-orphan-data',
        'mount_path' => '/data',
        'resource_id' => $this->environment->id,
        'resource_type' => $this->environment->getMorphClass(),
    ]);

    $graph = EnvironmentCanvasGraph::build($this->environment->fresh(['orphanVolumes']));
    $volume = collect($graph['nodes'])->first(
        fn (array $n): bool => $n['kind'] === 'volume' && ($n['orphan'] ?? false) && $n['label'] === 'kept-orphan-data'
    );

    expect($volume)->not->toBeNull();

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('openInspector', $volume['id'], 'settings')
        ->assertSet('inspector', 'node')
        ->assertSet('inspectorTab', 'settings')
        ->assertSet('inspectorKind', 'volume')
        ->assertSet('volumeIsOrphan', true)
        ->assertSee(__('Danger zone'))
        ->assertSee(__('Delete orphan volume'));

    expect(LocalPersistentVolume::find($orphan->id))->not->toBeNull();
});

it('renders the canvas page and persists dragged positions', function () {
    $appKey = 'stack-app:'.$this->odooApp->uuid;

    $component = Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->assertSuccessful()
        ->assertSee('Canvas')
        ->assertSee('gc-environment-canvas-root', false);

    $nodes = collect($component->get('graph')['nodes']);
    $odoo = $nodes->first(fn (array $node): bool => $node['id'] === $appKey);

    expect($odoo)->not->toBeNull()
        ->and($odoo['icon'])->toContain('odoo.svg')
        ->and($nodes->contains(fn (array $node): bool => $node['kind'] === 'github'))->toBeTrue()
        ->and(collect($odoo['domains'] ?? [])->pluck('label')->implode(','))->toContain('erp.example.test')
        ->and($odoo['label'])->toBe('odoo');

    $component->call('savePosition', $appKey, 320.5, 180.25)->assertSuccessful();

    $layout = EnvironmentCanvasLayout::query()->where('environment_id', $this->environment->id)->first();
    expect($layout)->not->toBeNull()
        ->and($layout->positionsMap()[$appKey]['x'])->toEqual(320.5)
        ->and($layout->positionsMap()[$appKey]['y'])->toEqual(180.3);
});

it('opens github and node inspectors on the canvas without leaving the page', function () {
    $githubApp = GithubApp::create([
        'name' => 'Canvas Inspect GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 5555,
        'installation_id' => 5555,
        'team_id' => $this->team->id,
        'is_public' => false,
    ]);
    $this->project->odooProfile?->update(['github_app_id' => $githubApp->id]);
    OdooEnvironmentBranch::query()->updateOrCreate(
        ['environment_id' => $this->environment->id],
        ['git_repository' => 'acme/erp', 'git_branch' => 'main', 'status' => 'idle'],
    );

    $appKey = 'stack-app:'.$this->odooApp->uuid;
    $githubKey = 'github:'.$this->service->uuid;

    $component = Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('openInspector', $githubKey, 'github')
        ->assertSet('inspector', 'github')
        ->assertSet('inspectorNodeId', $githubKey)
        ->assertSet('githubServiceUuid', $this->service->uuid)
        ->assertSee(__('GitHub'))
        ->assertSee(__('Open full configuration'))
        ->assertSee(__('Configuration'))
        ->assertSee(__('GitHub App'))
        ->assertSee(__('Connect with GitHub App'))
        ->call('openInspector', $appKey, 'details')
        ->assertSet('inspector', 'node')
        ->assertSet('inspectorTab', 'details')
        ->assertSet('inspectorNodeId', $appKey)
        ->assertSee(__('Stack rules'))
        ->assertSee(__('General configuration'));

    expect($component->get('stackRules'))->not->toBeEmpty()
        ->and($component->get('configurationUrl'))->toContain($this->service->uuid);

    $component
        ->call('setInspectorTab', 'logs')
        ->assertSet('inspectorTab', 'logs')
        ->call('closeInspector')
        ->assertSet('inspector', null);
});

it('sends config panel opens to the full service configuration page', function () {
    $appKey = 'stack-app:'.$this->odooApp->uuid;

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('openInspector', $appKey, 'config')
        ->assertRedirect(route('project.service.configuration', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'service_uuid' => $this->service->uuid,
        ]));
});

it('shows sentinel metrics inside the canvas inspector instead of navigating away', function () {
    $this->server->settings->update([
        'is_sentinel_enabled' => true,
        'is_metrics_enabled' => true,
    ]);

    $appKey = 'stack-app:'.$this->odooApp->uuid;

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('openInspector', $appKey, 'metrics')
        ->assertSet('inspectorTab', 'metrics')
        ->assertSet('metricsServerUuid', $this->server->uuid)
        ->assertSet('metricsEnabled', true)
        ->assertSee(__('Live server metrics from Sentinel'))
        ->assertDontSee(__('Open Sentinel'))
        ->assertSeeHtml('dashboard-server-metrics-'.$this->server->uuid);
});

it('resets the canvas layout', function () {
    EnvironmentCanvasLayout::query()->create([
        'environment_id' => $this->environment->id,
        'positions' => ['stack-app:'.$this->odooApp->uuid => ['x' => 10, 'y' => 20]],
        'groups' => [],
    ]);

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('resetLayout')
        ->assertDispatched('success');

    expect(EnvironmentCanvasLayout::query()->where('environment_id', $this->environment->id)->exists())->toBeFalse();
});

it('forbids members from saving canvas positions', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('savePosition', 'stack-app:'.$this->odooApp->uuid, 1, 1)
        ->assertForbidden();
});

it('polls live graph updates to the react canvas only when status changes', function () {
    $component = Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('pollGraph')
        ->assertNotDispatched('canvas-graph-updated');

    $this->odooApp->update(['status' => 'running:healthy']);

    $component
        ->call('pollGraph')
        ->assertDispatched('canvas-graph-updated');
});

it('exposes the canvas route from resources and the odoo hub', function () {
    expect(route('project.environment.canvas', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ]))->toContain('/canvas');

    $resources = file_get_contents(resource_path('views/livewire/project/resource/index.blade.php'));
    $hub = file_get_contents(resource_path('views/livewire/project/service/deployments.blade.php'));
    $canvas = file_get_contents(resource_path('views/livewire/project/environment/canvas.blade.php'));

    expect($resources)->toContain("route('project.environment.canvas'")
        ->and($hub)->toContain("route('project.environment.canvas'")
        ->and($canvas)->toContain("__('Sync')")
        ->and($canvas)->toContain("__('Add')")
        ->and($canvas)->toContain("__('List')")
        ->and($canvas)->toContain('canSaveStackTemplate')
        ->and($canvas)->toContain(':inline="true"')
        ->and($canvas)->toContain('gc-canvas-action--icon')
        ->and($canvas)->toContain('gc-canvas-page--workspace')
        ->and($canvas)->toContain('gc-canvas-inspector')
        ->and($canvas)->toContain('onInspect')
        ->and($canvas)->toContain("'list' => 1")
        ->and($canvas)->toContain('createAssistantOpen')
        ->and($canvas)->toContain('openCreateAssistant')
        ->and($canvas)->not->toContain('gc-canvas-hint')
        ->and($canvas)->not->toContain("'catalog' => 1");
});

it('opens a railway-style create assistant: category then service then connect', function () {
    $component = Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('openCreateAssistant')
        ->assertSet('createAssistantOpen', true)
        ->assertSet('createStep', 'category')
        ->assertSee(__('Add to canvas'))
        ->assertSee(__('Category'))
        ->assertSee(__('AI agent'))
        ->assertSee(__('Databases'))
        ->assertSee(__('Services'))
        ->assertSee(__('Artificial intelligence'));

    $categories = collect($component->viewData('createCategories'));
    expect($categories->pluck('key'))->toContain('agent')
        ->and($categories->pluck('key'))->toContain('github')
        ->and($categories->pluck('key'))->toContain('databases')
        ->and($categories->pluck('key'))->toContain('devtools');

    $component
        ->call('selectCreateCategory', 'devtools')
        ->assertSet('createStep', 'service')
        ->assertSet('createCategory', 'devtools')
        ->call('selectCreateOption', 'portainer')
        ->assertSet('createStep', 'configure')
        ->assertSet('selectedService', 'portainer')
        ->assertSee(__('Add to canvas'))
        ->assertSee(__('Deploy'));
});

it('adds the ai agent from the create assistant and hides it afterwards', function () {
    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('openCreateAssistant')
        ->call('selectCreateCategory', 'agent')
        ->assertSet('createAssistantOpen', false)
        ->assertDispatched('success');

    expect($this->project->fresh()->agent)->not->toBeNull();

    $categories = collect(Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])->call('openCreateAssistant')->viewData('createCategories'));

    expect($categories->pluck('key'))->not->toContain('agent');
});

it('adds a catalog service to the canvas without deploying and shows deploy when pending', function () {
    Bus::fake();

    $before = Service::query()->where('environment_id', $this->environment->id)->count();

    $component = Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('openCreateAssistant')
        ->call('selectCreateCategory', 'devtools')
        ->call('selectCreateOption', 'portainer')
        ->set('serverId', (string) $this->server->id)
        ->call('addCatalogServiceToCanvas')
        ->assertSet('createAssistantOpen', false)
        ->assertDispatched('success');

    $portainer = Service::query()
        ->where('environment_id', $this->environment->id)
        ->where('service_type', 'portainer')
        ->first();

    expect($portainer)->not->toBeNull()
        ->and(Service::query()->where('environment_id', $this->environment->id)->count())->toBe($before + 1);

    Bus::assertNotDispatched(StartService::class);

    $this->odooApp->update(['status' => 'running:healthy']);
    $portainer->applications()->update(['status' => 'exited:unhealthy']);

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->assertSee(__('Deploy'))
        ->assertSee(__('Add'))
        ->assertViewHas('pendingDeployCount', 1)
        ->assertViewHas('canDeployPending', true);

    $portainer->applications()->update(['status' => 'running:healthy']);

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->assertViewHas('pendingDeployCount', 0)
        ->assertViewHas('canDeployPending', false)
        ->assertDontSeeHtml('wire:click="deployPendingServices"');
});

it('hides save template until the stack is customized', function () {
    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->assertViewHas('canSaveStackTemplate', false)
        ->assertDontSee(__('Save template'));

    $destination = $this->service->destination;
    Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'name' => 'portainer-extra',
        'service_type' => 'portainer',
        'docker_compose_raw' => "services:\n  portainer:\n    image: portainer/portainer-ce:alpine\n",
    ]);

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->assertViewHas('canSaveStackTemplate', true)
        ->assertSee(__('Save template'));
});

it('lists connected canvas nodes and open urls in the inspector', function () {
    $graph = EnvironmentCanvasGraph::build($this->environment->fresh());
    $odoo = collect($graph['nodes'])->first(fn (array $n): bool => $n['id'] === 'stack-app:'.$this->odooApp->uuid);

    $portainer = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->service->destination_id,
        'destination_type' => $this->service->destination_type,
        'service_type' => 'portainer',
        'name' => 'portainer-admin',
        'docker_compose_raw' => "services:\n  portainer:\n    image: portainer/portainer-ce:alpine\n",
    ]);
    $portainerApp = ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'service_id' => $portainer->id,
        'name' => 'portainer',
        'human_name' => 'Portainer',
        'image' => 'portainer/portainer-ce:alpine',
        'fqdn' => 'https://portainer.example.test',
    ]);

    $graph = EnvironmentCanvasGraph::build($this->environment->fresh(['services.applications']));
    $odoo = collect($graph['nodes'])->first(fn (array $n): bool => $n['id'] === 'stack-app:'.$this->odooApp->uuid);
    $portainerNode = collect($graph['nodes'])->first(fn (array $n): bool => $n['id'] === 'stack-app:'.$portainerApp->uuid);

    expect($odoo)->not->toBeNull()->and($portainerNode)->not->toBeNull();

    EnvironmentCanvasLayout::query()->create([
        'environment_id' => $this->environment->id,
        'positions' => [],
        'groups' => [],
        'connections' => [[
            'id' => 'link-test',
            'from' => $portainerNode['id'],
            'to' => $odoo['id'],
            'label' => 'intranet',
        ]],
    ]);

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('openInspector', $odoo['id'], 'details')
        ->assertSee(__('Open service'))
        ->assertSee(__('Connected on canvas'))
        ->assertSee($portainerNode['label'])
        ->assertSee(__('Remove connection'))
        ->assertSee(__('Domains'))
        ->assertSee('https://erp.example.test')
        ->assertSet('inspectorStatusTone', fn (string $tone): bool => in_array($tone, ['running', 'stopped', 'pending', 'neutral'], true));
});
it('opens the canvas from the project overview environment links', function () {
    $component = Livewire::test(Show::class, [
        'project_uuid' => $this->project->uuid,
    ])->assertSuccessful();

    $row = collect($component->get('environmentPayload'))
        ->first(fn (array $environment): bool => $environment['uuid'] === $this->environment->uuid);

    $canvasUrl = route('project.environment.canvas', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ]);

    expect($row)->not->toBeNull()
        ->and($row['environmentHref'])->toBe($canvasUrl)
        ->and($row['href'])->toBeNull();

    $component->call('navigateToEnvironment', $this->project->uuid, $this->environment->uuid)
        ->assertRedirect($canvasUrl);
});

it('still mounts the full service configuration page route', function () {
    $this->get(route('project.service.configuration', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'service_uuid' => $this->service->uuid,
    ]))->assertSuccessful()
        ->assertSee(__('Compose resources'))
        ->assertSee(__('General'));
});

it('keeps general configuration as the default config view when github still needs a repository', function () {
    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'odoo-github',
        'private_key' => 'test-key',
        'is_git_related' => true,
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $githubApp = GithubApp::create([
        'name' => 'coollabsio',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 123,
        'installation_id' => 456,
        'private_key_id' => $keyId,
        'webhook_secret' => 'odoo-hook',
        'team_id' => $this->team->id,
        'is_public' => false,
    ]);
    DB::table('team_user')
        ->where('user_id', $this->user->id)
        ->where('team_id', $this->team->id)
        ->update(['github_app_id' => $githubApp->id]);

    $params = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'service_uuid' => $this->service->uuid,
    ];

    $this->get(route('project.service.configuration', $params))
        ->assertSuccessful()
        ->assertSee(__('Compose resources'))
        ->assertSee(__('General'))
        ->assertDontSee(__('Create a new repository'));

    $this->get(route('project.service.configuration', $params + ['odooPanel' => 'github']))
        ->assertSuccessful()
        ->assertSee(__('Create a new repository'))
        ->assertSee(__('GitHub'));
});

it('opens populated environments on the canvas from the resource index', function () {
    $this->get(route('project.resource.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ]))->assertRedirect(route('project.environment.canvas', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ]));
});

it('keeps advanced list and catalog entry on the resource index', function () {
    $this->get(route('project.resource.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'list' => 1,
    ]))->assertSuccessful();

    $this->get(route('project.resource.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'catalog' => 1,
    ]))->assertSuccessful();
});

it('keeps volumes as canvas orphans when a service is deleted without delete_volumes', function () {
    $volume = LocalPersistentVolume::create([
        'name' => $this->service->uuid.'-odoo-extra-addons',
        'mount_path' => '/mnt/extra-addons',
        'resource_id' => $this->odooApp->id,
        'resource_type' => $this->odooApp->getMorphClass(),
    ]);

    app(DeleteService::class)->deleteLocal($this->service->fresh(), preserveVolumes: true);

    expect(Service::find($this->service->id))->toBeNull()
        ->and($volume->fresh()->resource_type)->toBe($this->environment->getMorphClass())
        ->and((int) $volume->fresh()->resource_id)->toBe((int) $this->environment->id);

    $graph = EnvironmentCanvasGraph::build($this->environment->fresh(['orphanVolumes']));
    $orphan = collect($graph['nodes'])->first(
        fn (array $node): bool => ($node['id'] ?? null) === 'volume:'.$volume->id
    );

    expect($orphan)->not->toBeNull()
        ->and($orphan['orphan'])->toBeTrue()
        ->and($orphan['status_label'])->toBe(__('Orphan'))
        ->and($orphan['x'])->toBeGreaterThan(1000.0);
});

it('deletes an orphan volume from the canvas only after typing its name', function () {
    $volume = LocalPersistentVolume::create([
        'name' => 'kept-db-data',
        'mount_path' => '/var/lib/postgresql/data',
        'resource_id' => $this->environment->id,
        'resource_type' => $this->environment->getMorphClass(),
    ]);

    $nodeId = 'volume:'.$volume->id;

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('openInspector', $nodeId, 'settings')
        ->assertSet('volumeIsOrphan', true)
        ->assertSee(__('Delete orphan volume'))
        ->set('volumeDeleteConfirm', 'wrong-name')
        ->call('deleteOrphanVolume')
        ->assertDispatched('error');

    expect(LocalPersistentVolume::find($volume->id))->not->toBeNull();

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('openInspector', $nodeId, 'settings')
        ->set('volumeDeleteConfirm', 'kept-db-data')
        ->call('deleteOrphanVolume')
        ->assertDispatched('success');

    expect(LocalPersistentVolume::find($volume->id))->toBeNull();
});

it('persists lane positions for newly added catalog services so the graph stays a flow', function () {
    Bus::fake();

    $existing = EnvironmentCanvasLayout::query()->firstOrCreate(
        ['environment_id' => $this->environment->id],
        ['positions' => [], 'groups' => [], 'connections' => []],
    );
    $existingKey = 'stack-app:'.$this->odooApp->uuid;
    $existing->positions = [
        $existingKey => ['x' => 436.0, 'y' => 88.0],
    ];
    $existing->save();

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('openCreateAssistant')
        ->call('selectCreateCategory', 'devtools')
        ->call('selectCreateOption', 'portainer')
        ->set('serverId', (string) $this->server->id)
        ->call('addCatalogServiceToCanvas');

    $portainer = Service::query()
        ->where('environment_id', $this->environment->id)
        ->where('service_type', 'portainer')
        ->with('applications')
        ->firstOrFail();

    $portainerAppUuid = (string) ($portainer->applications->first()?->uuid ?? '');
    $newHub = $portainerAppUuid !== '' ? 'stack-app:'.$portainerAppUuid : null;

    $layout = EnvironmentCanvasLayout::query()
        ->where('environment_id', $this->environment->id)
        ->firstOrFail();
    $positions = $layout->positionsMap();

    expect($newHub)->not->toBeNull()
        ->and($positions)->toHaveKey($newHub)
        ->and($positions[$existingKey]['x'])->toBe(436.0)
        ->and($positions[$newHub]['x'])->toBeGreaterThan(90.0)
        ->and($positions[$newHub]['y'])->toBeGreaterThan(80.0);
});

it('links delete service from the canvas inspector to the configuration danger zone', function () {
    $graph = EnvironmentCanvasGraph::build($this->environment->fresh());
    $odoo = collect($graph['nodes'])->first(fn (array $n): bool => $n['id'] === 'stack-app:'.$this->odooApp->uuid);

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('openInspector', $odoo['id'], 'details')
        ->assertSet('deleteServiceUrl', route('project.service.danger', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'service_uuid' => $this->service->uuid,
        ]))
        ->assertSee(__('Delete service…'));
});

it('offers github in the create assistant and redirects to install when not connected', function () {
    $component = Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('openCreateAssistant');

    $categories = collect($component->viewData('createCategories'));
    expect($categories->pluck('key'))->toContain('github');

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('openCreateAssistant')
        ->call('selectCreateCategory', 'github')
        ->assertRedirect();
});

it('opens the github panel and remembers the app when the user is already connected', function () {
    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'canvas-github-key',
        'private_key' => 'test-key',
        'is_git_related' => true,
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $githubApp = GithubApp::create([
        'name' => 'Linked Canvas GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 7777,
        'installation_id' => 7777,
        'private_key_id' => $keyId,
        'team_id' => $this->team->id,
        'is_public' => false,
    ]);
    DB::table('team_user')
        ->where('user_id', $this->user->id)
        ->where('team_id', $this->team->id)
        ->update(['github_app_id' => $githubApp->id]);

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('connectGithubNode')
        ->assertSet('inspector', 'github')
        ->assertSet('githubServiceUuid', $this->service->uuid)
        ->assertDispatched('success');

    expect((int) DB::table('team_user')
        ->where('user_id', $this->user->id)
        ->where('team_id', $this->team->id)
        ->value('github_app_id'))->toBe($githubApp->id)
        ->and((int) $this->project->fresh()->odooProfile?->github_app_id)->toBe($githubApp->id);
});

it('saves the current canvas as a stack template under my stacks', function () {
    $this->service->update(['service_type' => 'odoo', 'connect_to_docker_network' => true]);
    $portainer = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->service->destination_id,
        'destination_type' => $this->service->destination_type,
        'service_type' => 'portainer',
        'name' => 'portainer-canvas',
        'docker_compose_raw' => "services:\n  portainer:\n    image: portainer/portainer-ce:latest\n",
        'connect_to_docker_network' => true,
    ]);
    ServiceApplication::create([
        'service_id' => $portainer->id,
        'name' => 'portainer',
        'human_name' => 'Portainer',
        'image' => 'portainer/portainer-ce:latest',
    ]);

    $layout = EnvironmentCanvasLayout::query()->firstOrCreate(
        ['environment_id' => $this->environment->id],
        ['positions' => [], 'groups' => [], 'connections' => []],
    );
    $layout->connections = [[
        'id' => 'link:odoo->portainer',
        'from' => 'stack-app:'.$this->odooApp->uuid,
        'to' => 'stack-app:'.$portainer->applications()->first()->uuid,
        'label' => 'intranet',
    ]];
    $layout->save();

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('openSaveStackTemplate')
        ->assertSet('saveStackOpen', true)
        ->set('stackTemplateName', 'Odoo + Portainer')
        ->set('stackTemplateDescription', 'Intranet stack')
        ->call('saveStackTemplate')
        ->assertSet('saveStackOpen', false)
        ->assertDispatched('success');

    $template = EnvironmentStackTemplate::query()->where('team_id', $this->team->id)->first();
    expect($template)->not->toBeNull()
        ->and($template->display_name)->toBe('Odoo + Portainer')
        ->and(collect($template->servicesList())->pluck('service_type')->all())->toContain('odoo', 'portainer')
        ->and($template->connectionsList())->not->toBeEmpty();

    $categories = collect(Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])->call('openCreateAssistant')->viewData('createCategories'));

    expect($categories->pluck('key'))->toContain('stacks');
});

it('applies a saved stack template without starting containers', function () {
    Bus::fake();

    $template = EnvironmentStackTemplateCatalog::saveFromEnvironment(
        $this->environment->fresh(['services.applications', 'services.databases', 'canvasLayout', 'project.odooProfile']),
        'Base Odoo',
        null,
        (int) $this->team->id,
        $this->user->id,
    );

    $emptyProject = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Blank']);
    $emptyProject->enableOdoo('20', 0, false);
    $emptyEnv = $emptyProject->environments()->where('name', 'production')->firstOrFail();

    Livewire::test(Canvas::class, [
        'project_uuid' => $emptyProject->uuid,
        'environment_uuid' => $emptyEnv->uuid,
    ])
        ->set('serverId', (string) $this->server->id)
        ->call('applyStackTemplate', $template->id)
        ->assertDispatched('success');

    expect($emptyEnv->fresh()->services)->not->toBeEmpty();
    Bus::assertNotDispatched(StartService::class);
});
