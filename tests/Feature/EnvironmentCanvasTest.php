<?php

use App\Livewire\Project\Environment\Canvas;
use App\Livewire\Project\Show;
use App\Models\EnvironmentCanvasLayout;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Support\EnvironmentCanvasGraph;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
});

it('builds one node per stack unit including github', function () {
    $graph = EnvironmentCanvasGraph::build($this->environment->fresh());

    $ids = collect($graph['nodes'])->pluck('id');
    $odoo = collect($graph['nodes'])->first(fn (array $n): bool => $n['id'] === 'stack-app:'.$this->odooApp->uuid);
    $github = collect($graph['nodes'])->first(fn (array $n): bool => $n['id'] === 'github:'.$this->service->uuid);

    expect($ids->contains('service:'.$this->service->uuid))->toBeFalse()
        ->and($odoo)->not->toBeNull()
        ->and($odoo['kind'])->toBe('odoo')
        ->and($odoo['panels'])->toContain('logs')
        ->and($odoo['container'])->toBe('odoo-'.$this->service->uuid)
        ->and($github)->not->toBeNull()
        ->and($github['kind'])->toBe('github')
        ->and($github['panels'])->toContain('github')
        ->and($github['glyph'])->toBe('github')
        ->and(collect($graph['nodes'])->contains(fn (array $n): bool => $n['kind'] === 'domain' && $n['glyph'] === 'globe' && str_contains($n['label'], 'erp.example.test')))->toBeTrue()
        ->and(collect($graph['nodes'])->contains(fn (array $n): bool => $n['kind'] === 'volume' && $n['glyph'] === 'disk' && $n['label'] === 'odoo-extra-addons'))->toBeTrue()
        ->and(collect($graph['edges'])->contains(fn (array $e): bool => $e['from'] === 'github:'.$this->service->uuid && $e['to'] === 'stack-app:'.$this->odooApp->uuid))->toBeTrue()
        ->and($graph['meta']['count'])->toBeGreaterThanOrEqual(3);
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
        ->and($nodes->contains(fn (array $node): bool => str_contains((string) $node['label'], 'erp.example.test')))->toBeTrue()
        ->and($odoo['label'])->toBe('odoo');

    $component->call('savePosition', $appKey, 320.5, 180.25)->assertSuccessful();

    $layout = EnvironmentCanvasLayout::query()->where('environment_id', $this->environment->id)->first();
    expect($layout)->not->toBeNull()
        ->and($layout->positionsMap()[$appKey]['x'])->toEqual(320.5)
        ->and($layout->positionsMap()[$appKey]['y'])->toEqual(180.3);
});

it('opens github and node inspectors on the canvas without leaving the page', function () {
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
        ->call('openInspector', $appKey, 'details')
        ->assertSet('inspector', 'node')
        ->assertSet('inspectorTab', 'details')
        ->assertSet('inspectorNodeId', $appKey)
        ->assertSee(__('Stack rules'));

    expect($component->get('stackRules'))->not->toBeEmpty();

    $component
        ->call('setInspectorTab', 'logs')
        ->assertSet('inspectorTab', 'logs')
        ->call('closeInspector')
        ->assertSet('inspector', null);
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

it('polls live graph updates to the react canvas', function () {
    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
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
        ->and($canvas)->toContain("__('Create')")
        ->and($canvas)->toContain("__('List')")
        ->and($canvas)->toContain('gc-canvas-page--workspace')
        ->and($canvas)->toContain('gc-canvas-inspector')
        ->and($canvas)->toContain('onInspect')
        ->and($canvas)->toContain("'list' => 1")
        ->and($canvas)->toContain("'catalog' => 1");
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
