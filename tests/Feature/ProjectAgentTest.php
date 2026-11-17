<?php

use App\Actions\Service\StartService;
use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Project\Agent\Settings;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ProjectAgent;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\ProjectAgent\ProjectAgentOdooModuleDraft;
use App\Services\ProjectAgent\ProjectAgentRunner;
use App\Services\ProjectAgent\ProjectAgentToolGate;
use App\Services\ProjectAgent\ProjectAgentTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Lorisleiva\Actions\Decorators\JobDecorator;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Agent Co']);
    $this->environment = $this->project->environments()->first()
        ?? $this->project->environments()->create(['name' => 'production', 'uuid' => (string) str()->uuid()]);
});

it('saves project agent provider and encrypted api key', function () {
    Livewire::test(Settings::class, ['project_uuid' => $this->project->uuid])
        ->set('provider', 'openai')
        ->set('model', 'gpt-4o-mini')
        ->set('apiKey', 'sk-test-secret')
        ->set('isEnabled', true)
        ->set('followInCloud', true)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $agent = ProjectAgent::query()->where('project_id', $this->project->id)->first();

    expect($agent)->not->toBeNull()
        ->and($agent->provider)->toBe('openai')
        ->and($agent->is_enabled)->toBeTrue()
        ->and($agent->api_key)->toBe('sk-test-secret')
        ->and($agent->isReady())->toBeTrue();
});

it('refuses services outside the project in the tool gate', function () {
    $otherTeam = Team::factory()->create();
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $otherEnvironment = $otherProject->environments()->first()
        ?? $otherProject->environments()->create(['name' => 'production', 'uuid' => (string) str()->uuid()]);

    $foreign = Service::factory()->create([
        'environment_id' => $otherEnvironment->id,
        'name' => 'foreign',
    ]);

    $gate = new ProjectAgentToolGate($this->project);

    expect(fn () => $gate->service($foreign->uuid))
        ->toThrow(RuntimeException::class, 'Service is outside this project.');
});

it('lists no services until the agent is connected on the canvas', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'name' => 'odoo-local',
    ]);

    $agent = ProjectAgent::query()->create([
        'project_id' => $this->project->id,
        'name' => 'Agent',
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'api_key' => 'sk-test',
        'is_enabled' => true,
    ]);

    $tools = new ProjectAgentTools($agent, new ProjectAgentToolGate($this->project, $agent));

    $listed = $tools->execute('list_project_services', []);
    expect($listed['services'])->toHaveCount(0);

    $blocked = $tools->execute('start_service', [
        'service_uuid' => $service->uuid,
        'confirm' => true,
    ]);
    expect($blocked['error'])->toContain('Connect the agent');
});

it('queues start when connected and confirm is true', function () {
    Bus::fake();

    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'name' => 'odoo-start',
    ]);

    $agent = ProjectAgent::query()->create([
        'project_id' => $this->project->id,
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'api_key' => 'sk-test',
        'is_enabled' => true,
        'settings' => ['connected_node_ids' => ['service:'.$service->uuid]],
    ]);

    $tools = new ProjectAgentTools($agent, new ProjectAgentToolGate($this->project, $agent));

    $listed = $tools->execute('list_project_services', []);
    expect($listed['services'])->toHaveCount(1)
        ->and($listed['services'][0]['uuid'])->toBe($service->uuid);

    $refused = $tools->execute('start_service', [
        'service_uuid' => $service->uuid,
        'confirm' => false,
    ]);
    expect($refused['error'])->toContain('confirm=true');

    $result = $tools->execute('start_service', [
        'service_uuid' => $service->uuid,
        'confirm' => true,
        'pull_latest_images' => true,
    ]);

    expect($result['queued'])->toBeTrue()
        ->and($result['action'])->toBe('start');

    Bus::assertDispatched(
        JobDecorator::class,
        fn (JobDecorator $job): bool => $job->decorates(StartService::class),
    );
});
it('lists finished deployments and queues addons rollback', function () {
    Bus::fake();

    $server = Server::factory()->create(['team_id' => $this->team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);

    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'name' => 'odoo-roll',
    ]);

    $addons = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'name' => 'odoo-addons',
        'is_odoo_addons' => true,
    ]);

    ApplicationDeploymentQueue::query()->create([
        'application_id' => $addons->id,
        'application_name' => $addons->name,
        'deployment_uuid' => 'dep-old',
        'deployment_url' => '/d/old',
        'server_id' => $server->id,
        'server_name' => $server->name,
        'destination_id' => $destination->id,
        'commit' => 'abc1234',
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'pull_request_id' => 0,
    ]);

    $agent = ProjectAgent::query()->create([
        'project_id' => $this->project->id,
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'api_key' => 'sk-test',
        'is_enabled' => true,
        'settings' => ['connected_node_ids' => ['service:'.$service->uuid]],
    ]);

    $tools = new ProjectAgentTools($agent, new ProjectAgentToolGate($this->project, $agent));

    $history = $tools->execute('list_deployments', ['service_uuid' => $service->uuid]);
    expect($history['deployments'])->toHaveCount(1)
        ->and($history['deployments'][0]['commit'])->toBe('abc1234');

    $rollback = $tools->execute('rollback_addons', [
        'service_uuid' => $service->uuid,
        'commit' => 'abc1234',
        'confirm' => true,
    ]);

    expect($rollback['queued'])->toBeTrue()
        ->and($rollback['action'])->toBe('rollback_addons');

    expect(ApplicationDeploymentQueue::query()
        ->where('application_id', $addons->id)
        ->where('rollback', true)
        ->where('commit', 'abc1234')
        ->exists())->toBeTrue();
});

it('drafts and saves an odoo module scaffold', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'name' => 'odoo-module',
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);

    $agent = ProjectAgent::query()->create([
        'project_id' => $this->project->id,
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'api_key' => 'sk-test',
        'is_enabled' => true,
        'settings' => ['connected_node_ids' => ['service:'.$service->uuid]],
    ]);

    $tools = new ProjectAgentTools($agent, new ProjectAgentToolGate($this->project, $agent));
    $result = $tools->execute('draft_odoo_module', [
        'service_uuid' => $service->uuid,
        'technical_name' => 'gpsh_demo',
        'display_name' => 'GPSH Demo',
        'model_name' => 'ticket',
        'save' => true,
    ]);

    expect($result['technical_name'])->toBe('gpsh_demo')
        ->and($result['saved'])->toBeTrue()
        ->and($result['markdown'])->toContain('gpsh_demo')
        ->and($result['files'])->toContain('__manifest__.py');

    $agent->refresh();
    $manifest = $agent->settings['drafts']['gpsh_demo']['files']['__manifest__.py'] ?? null;
    expect($manifest)->toBeString()->toContain('GPSH Demo');

    $commands = ProjectAgentOdooModuleDraft::volumeWriteCommands(
        Service::factory()->create([
            'environment_id' => $this->environment->id,
            'uuid' => 'svc-uuid-demo',
            'name' => 'odoo-demo',
        ]),
        'gpsh_demo',
        $agent->settings['drafts']['gpsh_demo']['files'],
    );

    expect($commands[0])->toContain('docker volume create')
        ->and($commands[1])->toContain('svc-uuid-demo_odoo-extra-addons')
        ->and($commands[1])->toContain('base64');
});

it('runs tool calls then returns the final assistant reply', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'name' => 'odoo-chat',
    ]);

    $agent = ProjectAgent::query()->create([
        'project_id' => $this->project->id,
        'provider' => 'openai',
        'model' => 'gpt-4o-mini',
        'api_key' => 'sk-test',
        'is_enabled' => true,
        'settings' => ['connected_node_ids' => ['service:'.$service->uuid]],
    ]);

    Http::fake([
        'api.openai.com/*' => Http::sequence()
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => [
                                'name' => 'list_connected_context',
                                'arguments' => '{}',
                            ],
                        ]],
                    ],
                ]],
            ])
            ->push([
                'choices' => [[
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'This project has '.$service->name.'.',
                    ],
                ]],
            ]),
    ]);

    $runner = new ProjectAgentRunner($agent, new ProjectAgentToolGate($this->project, $agent));
    $reply = $runner->chat('What services do I have?', [], $this->environment->uuid);

    expect($reply['content'])->toContain('odoo-chat')
        ->and($reply['meta']['tools_used'])->toContain('list_connected_context');
});
