<?php

use App\Livewire\Dashboard;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\DashboardOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->user = User::factory()->create(['name' => 'Ada Lovelace']);
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('maps stored statuses to dashboard states', function (?string $status, string $state) {
    expect(DashboardOverview::stateFor($status))->toBe($state);
})->with([
    ['running:healthy', DashboardOverview::STATE_ONLINE],
    ['running:unknown', DashboardOverview::STATE_ONLINE],
    ['running:unhealthy', DashboardOverview::STATE_DEGRADED],
    ['degraded:unhealthy', DashboardOverview::STATE_DEGRADED],
    ['starting:unhealthy', DashboardOverview::STATE_STARTING],
    ['restarting', DashboardOverview::STATE_STARTING],
    ['exited', DashboardOverview::STATE_OFFLINE],
    ['exited:unhealthy', DashboardOverview::STATE_OFFLINE],
    ['', DashboardOverview::STATE_OFFLINE],
    [null, DashboardOverview::STATE_OFFLINE],
]);

it('summarizes health per project and lists what needs attention', function () {
    $project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Acme ERP']);
    $production = $project->environments()->where('role', Environment::ROLE_PRODUCTION)->firstOrFail();
    $staging = Environment::factory()->staging()->create(['project_id' => $project->id, 'name' => 'staging-1']);

    Application::factory()->create(['environment_id' => $production->id, 'name' => 'web', 'status' => 'running:healthy']);
    Application::factory()->create(['environment_id' => $production->id, 'name' => 'worker', 'status' => 'running:unhealthy']);
    Application::factory()->create(['environment_id' => $staging->id, 'name' => 'preview', 'status' => 'exited']);

    $overview = app(DashboardOverview::class)->build(
        Project::query()->whereKey($project->id)->with('environments')->get(),
        collect(),
    );

    $row = $overview['projects']->first();

    expect($row['resources'])->toBe(3)
        ->and($row['online'])->toBe(1)
        ->and($row['degraded'])->toBe(1)
        ->and($row['offline'])->toBe(1)
        ->and($row['production'])->toBe(1)
        ->and($row['staging'])->toBe(1)
        ->and($row['health'])->toBe('degraded')
        ->and($overview['attentionTotal'])->toBe(1)
        ->and($overview['attention']->pluck('name')->all())->toBe(['worker'])
        ->and($overview['attention']->first()['context'])->toBe('Acme ERP / production')
        ->and($overview['totals']['online'])->toBe(1);
});

it('treats a stopped service as neutral and a crash loop as attention', function () {
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $production = $project->environments()->where('role', Environment::ROLE_PRODUCTION)->firstOrFail();

    Application::factory()->create(['environment_id' => $production->id, 'name' => 'paused-app', 'status' => 'exited:unhealthy']);
    Application::factory()->create([
        'environment_id' => $production->id,
        'name' => 'looping-app',
        'status' => 'exited:unhealthy',
        'restart_count' => 10,
        'max_restart_count' => 10,
        'last_restart_type' => 'crash',
    ]);

    $overview = app(DashboardOverview::class)->build(
        Project::query()->whereKey($project->id)->with('environments')->get(),
        collect(),
    );

    expect($overview['attention']->pluck('name')->all())->toBe(['looping-app'])
        ->and($overview['attention']->first()['status'])->toBe('Crashed')
        ->and($overview['projects']->first()['health'])->toBe('degraded')
        ->and($overview['totals']['offline'])->toBe(2);
});

it('tells the user when every service is stopped without raising an alert', function () {
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $production = $project->environments()->where('role', Environment::ROLE_PRODUCTION)->firstOrFail();
    Application::factory()->create(['environment_id' => $production->id, 'status' => 'exited:unhealthy']);
    Application::factory()->create(['environment_id' => $production->id, 'status' => 'exited:unhealthy']);

    Livewire::test(Dashboard::class)
        ->assertSee('All 2 services are stopped.')
        ->assertSee('All clear');
});

it('does not include resources from another team', function () {
    $otherTeam = Team::factory()->create();
    $foreignProject = Project::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Foreign Project']);
    $foreignEnvironment = $foreignProject->environments()->where('role', Environment::ROLE_PRODUCTION)->firstOrFail();
    Application::factory()->create(['environment_id' => $foreignEnvironment->id, 'name' => 'foreign-app', 'status' => 'exited']);

    Livewire::test(Dashboard::class)
        ->assertDontSee('Foreign Project')
        ->assertDontSee('foreign-app')
        ->assertSee('All clear');
});

it('shows the status hero, attention list and project health', function () {
    $project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Acme ERP']);
    $production = $project->environments()->where('role', Environment::ROLE_PRODUCTION)->firstOrFail();
    Application::factory()->create(['environment_id' => $production->id, 'name' => 'broken-worker', 'status' => 'running:unhealthy']);

    Livewire::test(Dashboard::class)
        ->assertSee('Ada')
        ->assertSee('1 item needs your attention.')
        ->assertSee('Acme ERP')
        ->assertSee('broken-worker')
        ->assertSee('Acme ERP / production')
        ->assertSee('Unhealthy')
        ->assertDontSee('All clear');
});

it('guides a new team through a first-run checklist', function () {
    Livewire::test(Dashboard::class)
        ->assertSee('Let’s get your first project online.')
        ->assertSee('Add an SSH key')
        ->assertSee('Connect a server')
        ->assertSee('Create a project')
        ->assertSee('Launch your first service');
});

it('hides server setup steps from a member', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Dashboard::class)
        ->assertSee('Create a project')
        ->assertDontSee('Add an SSH key')
        ->assertDontSee('Connect a server');
});
