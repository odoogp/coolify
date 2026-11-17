<?php

use App\Livewire\Project\Agent\CloudShell;
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

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
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
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
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

it('does not show an ai agent node until the user adds one', function () {
    $graph = EnvironmentCanvasGraph::build($this->environment->fresh());
    $agent = collect($graph['nodes'])->first(fn (array $n): bool => $n['kind'] === 'agent');

    expect($agent)->toBeNull();
});

it('adds an ai agent node from the canvas toolbar', function () {
    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('addAgentNode')
        ->assertSet('inspector', 'agent')
        ->assertSet('inspectorTab', 'settings')
        ->assertDispatched('success');

    $graph = EnvironmentCanvasGraph::build($this->environment->fresh(['project.agent']));
    $agent = collect($graph['nodes'])->first(fn (array $n): bool => $n['kind'] === 'agent');

    expect($agent)->not->toBeNull()
        ->and($agent['id'])->toBe('agent:'.$this->project->uuid)
        ->and($agent['panels'])->toContain('chat')
        ->and($agent['panels'])->toContain('settings');
});

it('persists a user connection between the agent and a service node', function () {
    $appKey = 'stack-app:'.$this->odooApp->uuid;
    $agentKey = 'agent:'.$this->project->uuid;

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('addAgentNode')
        ->call('saveConnection', $agentKey, $appKey)
        ->assertSuccessful();

    $layout = EnvironmentCanvasLayout::query()->where('environment_id', $this->environment->id)->first();
    expect($layout)->not->toBeNull()
        ->and(collect($layout->connectionsList())->contains(
            fn (array $row): bool => $row['from'] === $agentKey && $row['to'] === $appKey
        ))->toBeTrue();

    $agent = $this->project->agent()->first();
    expect($agent)->not->toBeNull()
        ->and($agent->connectedNodeIds())->toContain($appKey);
});

it('opens the agent chat inspector on the canvas', function () {
    $agentKey = 'agent:'.$this->project->uuid;

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('addAgentNode')
        ->call('openInspector', $agentKey, 'chat')
        ->assertSet('inspector', 'agent')
        ->assertSet('inspectorTab', 'chat')
        ->assertSee(__('Scoped to this project only.'))
        ->assertDispatched('canvas-agent-side-chat');
});

it('hides the floating agent cloud while any canvas inspector is open', function () {
    $appKey = 'stack-app:'.$this->odooApp->uuid;

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('openInspector', $appKey, 'details')
        ->assertSet('inspector', 'node')
        ->assertDispatched('canvas-agent-side-chat');

    $cloud = Livewire::test(CloudShell::class)
        ->call('setSideChatActive', true);

    expect($cloud->get('suppressedBySideChat'))->toBeTrue();
    $cloud->assertDontSee(__('Project agent'), false);

    $cloud->call('setSideChatActive', false);
    expect($cloud->get('suppressedBySideChat'))->toBeFalse();
});

it('persists an intranet link between two services and joins the destination network flag', function () {
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $portainer = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'name' => 'portainer-admin',
        'service_type' => 'portainer',
        'connect_to_docker_network' => false,
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

    $odooKey = 'stack-app:'.$this->odooApp->uuid;
    $portainerKey = 'stack-app:'.$portainerApp->uuid;

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('saveConnection', $portainerKey, $odooKey)
        ->assertSuccessful()
        ->assertDispatched('success');

    $layout = EnvironmentCanvasLayout::query()->where('environment_id', $this->environment->id)->first();
    expect($layout)->not->toBeNull()
        ->and(collect($layout->connectionsList())->contains(
            fn (array $row): bool => $row['from'] === $portainerKey
                && $row['to'] === $odooKey
                && $row['label'] === 'intranet'
        ))->toBeTrue()
        ->and((bool) $portainer->fresh()->connect_to_docker_network)->toBeTrue()
        ->and((bool) $this->service->fresh()->connect_to_docker_network)->toBeTrue();

    $graph = EnvironmentCanvasGraph::build($this->environment->fresh(['canvasLayout', 'services.applications']));
    expect(collect($graph['edges'])->contains(
        fn (array $edge): bool => $edge['from'] === $portainerKey
            && $edge['to'] === $odooKey
            && ($edge['label'] ?? null) === 'intranet'
    ))->toBeTrue();
});

it('removes a persisted intranet connection from the canvas', function () {
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $portainer = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'name' => 'portainer-admin',
        'service_type' => 'portainer',
        'connect_to_docker_network' => true,
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

    $odooKey = 'stack-app:'.$this->odooApp->uuid;
    $portainerKey = 'stack-app:'.$portainerApp->uuid;

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('saveConnection', $portainerKey, $odooKey)
        ->call('removeConnection', $portainerKey, $odooKey)
        ->assertSuccessful()
        ->assertDispatched('success');

    $layout = EnvironmentCanvasLayout::query()->where('environment_id', $this->environment->id)->first();
    expect($layout)->not->toBeNull()
        ->and(collect($layout->connectionsList())->contains(
            fn (array $row): bool => $row['from'] === $portainerKey && $row['to'] === $odooKey
        ))->toBeFalse();

    $graph = EnvironmentCanvasGraph::build($this->environment->fresh(['canvasLayout', 'services.applications']));
    expect(collect($graph['edges'])->contains(
        fn (array $edge): bool => $edge['from'] === $portainerKey && $edge['to'] === $odooKey
    ))->toBeFalse();
});

it('removes an agent context connection from layout and agent settings', function () {
    $appKey = 'stack-app:'.$this->odooApp->uuid;
    $agentKey = 'agent:'.$this->project->uuid;

    Livewire::test(Canvas::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->call('addAgentNode')
        ->call('saveConnection', $agentKey, $appKey)
        ->call('removeConnection', $agentKey, $appKey)
        ->assertSuccessful()
        ->assertDispatched('success');

    $layout = EnvironmentCanvasLayout::query()->where('environment_id', $this->environment->id)->first();
    expect(collect($layout?->connectionsList() ?? [])->contains(
        fn (array $row): bool => $row['from'] === $agentKey && $row['to'] === $appKey
    ))->toBeFalse();

    $agent = $this->project->agent()->first();
    expect($agent)->not->toBeNull()
        ->and($agent->connectedNodeIds())->not->toContain($appKey);
});
