<?php

use App\Livewire\Project\Environment\Canvas;
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

    ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'service_id' => $this->service->id,
        'name' => 'odoo',
        'human_name' => 'Odoo',
        'image' => 'odoo:20',
        'fqdn' => 'https://erp.example.test',
    ]);
});

it('builds canvas nodes for odoo service domain and named volume', function () {
    $graph = EnvironmentCanvasGraph::build($this->environment->fresh());

    $ids = collect($graph['nodes'])->pluck('id');
    $odoo = collect($graph['nodes'])->first(fn (array $n): bool => $n['id'] === 'service:'.$this->service->uuid);

    expect($ids->contains('service:'.$this->service->uuid))->toBeTrue()
        ->and($odoo['kind'])->toBe('odoo')
        ->and($odoo['icon'])->toContain('odoo.svg')
        ->and($odoo['logs_href'])->toContain('/logs')
        ->and(collect($graph['nodes'])->contains(fn (array $n): bool => $n['kind'] === 'domain' && $n['glyph'] === 'globe' && str_contains($n['label'], 'erp.example.test')))->toBeTrue()
        ->and(collect($graph['nodes'])->contains(fn (array $n): bool => $n['kind'] === 'volume' && $n['glyph'] === 'disk' && $n['label'] === 'odoo-extra-addons'))->toBeTrue()
        ->and($graph['edges'])->not->toBeEmpty()
        ->and(collect($graph['edges'])->every(fn (array $edge): bool => filled($edge['from']) && filled($edge['to'])))->toBeTrue()
        ->and($graph['meta']['count'])->toBeGreaterThanOrEqual(3);
});

it('renders the canvas page and persists dragged positions', function () {
    $serviceKey = 'service:'.$this->service->uuid;

    $component = Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->assertSuccessful()
        ->assertSee('Canvas')
        ->assertSee('gc-environment-canvas-root', false);

    $nodes = collect($component->get('graph')['nodes']);
    $odoo = $nodes->first(fn (array $node): bool => $node['id'] === $serviceKey);

    expect($odoo)->not->toBeNull()
        ->and($odoo['icon'])->toContain('odoo.svg')
        ->and($nodes->contains(fn (array $node): bool => str_contains((string) $node['label'], 'erp.example.test')))->toBeTrue()
        ->and($odoo['label'])->toBe('odoo-web');

    $component->call('savePosition', $serviceKey, 320.5, 180.25)->assertSuccessful();

    $layout = EnvironmentCanvasLayout::query()->where('environment_id', $this->environment->id)->first();
    expect($layout)->not->toBeNull()
        ->and($layout->positionsMap()[$serviceKey]['x'])->toEqual(320.5)
        ->and($layout->positionsMap()[$serviceKey]['y'])->toEqual(180.3);
});

it('resets the canvas layout', function () {
    EnvironmentCanvasLayout::query()->create([
        'environment_id' => $this->environment->id,
        'positions' => ['service:'.$this->service->uuid => ['x' => 10, 'y' => 20]],
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
        ->call('savePosition', 'service:'.$this->service->uuid, 1, 1)
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

    expect($resources)->toContain("route('project.environment.canvas'")
        ->and($hub)->toContain("route('project.environment.canvas'");
});
