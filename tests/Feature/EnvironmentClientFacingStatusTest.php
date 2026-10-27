<?php

use App\Models\InstanceSettings;
use App\Models\OdooEnvironmentBranch;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Team;
use App\Models\User;
use App\Support\GpshNotices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    Cache::flush();
    seedOdooComposeVersions(['20']);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'created_by' => $this->user->id]);
    $this->project->enableOdoo('20');
    $this->environment = $this->project->productionEnvironment();
});

it('shows ready when odoo is running even if the addons sync branch is failed', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);
    ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'odoo',
        'image' => 'odoo:20',
        'status' => 'running:unknown',
    ]);
    OdooEnvironmentBranch::query()->create([
        'environment_id' => $this->environment->id,
        'service_id' => $service->id,
        'git_branch' => 'Produccion',
        'status' => 'failed',
    ]);

    $this->environment->load(['services.applications', 'odooBranch']);

    expect($this->environment->clientFacingStatus($service->fresh()))->toBe('ready');
});

it('keeps updating while addons sync is in progress', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);
    ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'odoo',
        'image' => 'odoo:20',
        'status' => 'running:unknown',
    ]);
    OdooEnvironmentBranch::query()->create([
        'environment_id' => $this->environment->id,
        'service_id' => $service->id,
        'git_branch' => 'Produccion',
        'status' => 'updating',
    ]);

    $this->environment->load(['services.applications', 'odooBranch']);

    expect($this->environment->clientFacingStatus($service->fresh()))->toBe('updating');
});

it('shows failed only when the service is down and the branch sync failed', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);
    ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'odoo',
        'image' => 'odoo:20',
        'status' => 'exited:unhealthy',
    ]);
    OdooEnvironmentBranch::query()->create([
        'environment_id' => $this->environment->id,
        'service_id' => $service->id,
        'git_branch' => 'Produccion',
        'status' => 'failed',
    ]);

    $this->environment->load(['services.applications', 'odooBranch']);

    expect($this->environment->clientFacingStatus($service->fresh()))->toBe('failed');
});

it('clears a failed branch status when odoo becomes accessible', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);
    $branch = OdooEnvironmentBranch::query()->create([
        'environment_id' => $this->environment->id,
        'service_id' => $service->id,
        'git_branch' => 'Produccion',
        'status' => 'failed',
    ]);

    GpshNotices::announce($service->fresh(['environment.odooBranch', 'environment.project']), 'accessible');

    expect($branch->fresh()->status)->toBe('idle');
});
