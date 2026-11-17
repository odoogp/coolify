<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Project\Service\Deployments;
use App\Livewire\Project\Service\DeployPipeline;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\OdooEnvironmentBranch;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceChangeDraft;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Support\OdooDeployPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'odoo_base_domain' => 'getodoo.sh',
    ]));
    seedOdooComposeVersions();

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->project->enableOdoo('20', 0, false);
    $this->environment = $this->project->environments()->where('name', 'production')->firstOrFail();

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'ip' => '203.0.113.10',
    ]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);

    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $this->server->id]);

    $this->service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
        'name' => 'odoo-demo',
    ]);

    OdooEnvironmentBranch::query()->updateOrCreate(
        ['environment_id' => $this->environment->id],
        ['git_branch' => 'main', 'status' => 'idle', 'subdomain' => 'demo', 'workers' => 1],
    );

    $this->addons = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'name' => 'odoo-addons',
        'is_odoo_addons' => true,
    ]);
});

function hubParams(): array
{
    return [
        'project_uuid' => test()->project->uuid,
        'environment_uuid' => test()->environment->uuid,
        'service_uuid' => test()->service->uuid,
    ];
}

it('maps odoo module deploy statuses onto the pipeline stages', function () {
    $idle = OdooDeployPipeline::fromDeployment(null);
    expect($idle['status'])->toBe('idle')
        ->and($idle['stages'][0]['state'])->toBe('idle');

    $queued = ApplicationDeploymentQueue::create([
        'application_id' => $this->addons->id,
        'application_name' => $this->addons->name,
        'deployment_uuid' => 'd-queued',
        'deployment_url' => '/d/queued',
        'server_id' => $this->server->id,
        'server_name' => $this->server->name,
        'status' => ApplicationDeploymentStatus::QUEUED->value,
        'pull_request_id' => 0,
    ]);
    expect(OdooDeployPipeline::fromDeployment($queued)['current'])->toBe('git')
        ->and(OdooDeployPipeline::fromDeployment($queued)['active'])->toBeTrue();

    $syncing = ApplicationDeploymentQueue::create([
        'application_id' => $this->addons->id,
        'application_name' => $this->addons->name,
        'deployment_uuid' => 'd-sync',
        'deployment_url' => '/d/sync',
        'server_id' => $this->server->id,
        'server_name' => $this->server->name,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'logs' => 'Cloning into volume…',
        'pull_request_id' => 0,
    ]);
    expect(OdooDeployPipeline::fromDeployment($syncing)['current'])->toBe('sync');

    $restarting = ApplicationDeploymentQueue::create([
        'application_id' => $this->addons->id,
        'application_name' => $this->addons->name,
        'deployment_uuid' => 'd-restart',
        'deployment_url' => '/d/restart',
        'server_id' => $this->server->id,
        'server_name' => $this->server->name,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'logs' => "Clone finished. Modules are on disk.\nRestarting Odoo and refreshing the Apps list…",
        'pull_request_id' => 0,
    ]);
    $restartPipeline = OdooDeployPipeline::fromDeployment($restarting);
    expect($restartPipeline['current'])->toBe('restart')
        ->and(collect($restartPipeline['stages'])->firstWhere('key', 'git')['state'])->toBe('done');

    $finished = ApplicationDeploymentQueue::create([
        'application_id' => $this->addons->id,
        'application_name' => $this->addons->name,
        'deployment_uuid' => 'd-done',
        'deployment_url' => '/d/done',
        'server_id' => $this->server->id,
        'server_name' => $this->server->name,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'logs' => 'Odoo restart finished.',
        'pull_request_id' => 0,
    ]);
    expect(OdooDeployPipeline::fromDeployment($finished)['current'])->toBe('running')
        ->and(OdooDeployPipeline::fromDeployment($finished)['failed'])->toBeFalse();

    $failed = ApplicationDeploymentQueue::create([
        'application_id' => $this->addons->id,
        'application_name' => $this->addons->name,
        'deployment_uuid' => 'd-fail',
        'deployment_url' => '/d/fail',
        'server_id' => $this->server->id,
        'server_name' => $this->server->name,
        'status' => ApplicationDeploymentStatus::FAILED->value,
        'logs' => 'Cloning into volume…',
        'pull_request_id' => 0,
    ]);
    expect(OdooDeployPipeline::fromDeployment($failed)['failed'])->toBeTrue()
        ->and(OdooDeployPipeline::fromDeployment($failed)['current'])->toBe('sync');
});

it('renders the odoo service hub with overview deploys and runtime tabs', function () {
    Livewire::test(Deployments::class, hubParams())
        ->assertSuccessful()
        ->assertSet('isOdooService', true)
        ->assertSet('tab', 'overview')
        ->assertSee('Overview')
        ->assertSee('Deploys')
        ->assertSee('Runtime')
        ->assertSee('Latest update')
        ->call('setTab', 'deploys')
        ->assertSet('tab', 'deploys')
        ->call('setTab', 'runtime')
        ->assertSet('tab', 'runtime')
        ->assertSee('Subdomain');
});

it('stores runtime edits as a draft until apply and discard', function () {
    Bus::fake();

    $component = Livewire::test(Deployments::class, hubParams())
        ->call('setTab', 'runtime')
        ->set('draftSubdomain', 'acme')
        ->set('draftWorkers', 3)
        ->assertSet('hasDraft', true)
        ->assertSee('changes in review');

    expect(ServiceChangeDraft::query()->where('service_id', $this->service->id)->exists())->toBeTrue();

    $component->call('toggleDiff')
        ->assertSee('acme')
        ->assertSee('3');

    $component->call('applyDraft')
        ->assertDispatched('success');

    expect(ServiceChangeDraft::query()->where('service_id', $this->service->id)->exists())->toBeFalse()
        ->and($this->environment->odooBranch()->first()->subdomain)->toBe('acme')
        ->and($this->environment->odooBranch()->first()->workers)->toBe(3);

    Livewire::test(Deployments::class, hubParams())
        ->call('setTab', 'runtime')
        ->set('draftWorkers', 8)
        ->assertSet('hasDraft', true)
        ->call('discardDraft')
        ->assertDispatched('success');

    expect(ServiceChangeDraft::query()->where('service_id', $this->service->id)->exists())->toBeFalse()
        ->and($this->environment->odooBranch()->first()->workers)->toBe(3);
});

it('forbids members without update access from applying a draft', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    ServiceChangeDraft::query()->create([
        'service_id' => $this->service->id,
        'user_id' => $member->id,
        'payload' => ['subdomain' => 'nope', 'workers' => 9],
    ]);

    Livewire::test(Deployments::class, hubParams())
        ->call('applyDraft')
        ->assertForbidden();

    expect($this->environment->odooBranch()->first()->subdomain)->toBe('demo');
});

it('polls the deploy pipeline while a sync is in progress', function () {
    ApplicationDeploymentQueue::create([
        'application_id' => $this->addons->id,
        'application_name' => $this->addons->name,
        'deployment_uuid' => 'd-live',
        'deployment_url' => '/d/live',
        'server_id' => $this->server->id,
        'server_name' => $this->server->name,
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
        'logs' => 'Running git clone into the addons volume…',
        'commit_message' => 'Update modules',
        'pull_request_id' => 0,
    ]);

    Livewire::test(DeployPipeline::class, [
        'service' => $this->service,
        'application' => $this->addons,
    ])
        ->assertSuccessful()
        ->assertSee('Sync')
        ->assertSee('Update modules')
        ->assertSeeHtml('wire:poll.4s');
});

it('lists deploys in global search navigation', function () {
    $search = file_get_contents(app_path('Livewire/GlobalSearch.php'));

    expect($search)
        ->toContain("'name' => 'Deploys'")
        ->toContain("route('deployments.index')");
});
