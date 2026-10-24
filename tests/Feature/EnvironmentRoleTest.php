<?php

use App\Jobs\CloneEnvironmentStagingJob;
use App\Livewire\Project\Show;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
    ]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'created_by' => $this->user->id]);
});

it('marks the first project environment as production by role', function () {
    $production = $this->project->productionEnvironment();

    expect($production)->toBeInstanceOf(Environment::class)
        ->and($production->isProduction())->toBeTrue()
        ->and($production->role)->toBe(Environment::ROLE_PRODUCTION)
        ->and($production->name)->toBe('production');
});

it('keeps production identity when the display name is renamed', function () {
    $production = $this->project->productionEnvironment();
    $production->update(['name' => 'main']);

    expect($production->fresh()->isProduction())->toBeTrue()
        ->and($this->project->fresh()->productionEnvironment()?->name)->toBe('main');
});

it('creates staging with role staging and allows another production for a different service', function () {
    $staging = $this->project->cloneProductionAsStaging();

    expect($staging->isStaging())->toBeTrue()
        ->and($staging->role)->toBe(Environment::ROLE_STAGING)
        ->and($staging->name)->toStartWith('staging');

    $secondProduction = $this->project->createNextProductionEnvironment('demo-worker');

    expect($secondProduction->isProduction())->toBeTrue()
        ->and($secondProduction->name)->toBe('demo-worker')
        ->and($this->project->fresh()->environments()->where('role', Environment::ROLE_PRODUCTION)->count())->toBe(2);
});

it('clones non-odoo production services into a new staging environment', function () {
    $production = $this->project->productionEnvironment();
    Service::factory()->create([
        'environment_id' => $production->id,
        'name' => 'n8n-production',
        'docker_compose_raw' => "services:\n  n8n:\n    image: n8nio/n8n\n",
        'server_id' => null,
    ]);

    Auth::logout();

    (new CloneEnvironmentStagingJob(
        $this->project->id,
        $production->uuid,
        'env-clone-generic',
        $this->user->id,
    ))->handle();

    $status = Cache::get('env-clone-generic');
    $staging = $this->project->environments()->where('role', Environment::ROLE_STAGING)->first();

    expect($status['error'] ?? null)->toBeNull()
        ->and($status['done'] ?? false)->toBeTrue()
        ->and($staging)->toBeInstanceOf(Environment::class)
        ->and($staging->isStaging())->toBeTrue()
        ->and($staging->services()->count())->toBe(1)
        ->and($staging->services()->first()->docker_compose_raw)->toContain('n8nio/n8n');
});

it('shows clone on production with services for non-odoo projects', function () {
    $production = $this->project->productionEnvironment();
    Service::factory()->create([
        'environment_id' => $production->id,
        'name' => 'app-production',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx\n",
        'server_id' => null,
    ]);

    $html = Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->call('selectEnvironment', $production->uuid)
        ->html();

    expect($html)->toContain(__('Clone'));

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->call('selectEnvironment', $production->uuid)
        ->call('openCloneWizard')
        ->assertSet('showCloneWizard', true)
        ->call('cloneToStaging');

    expect($this->project->fresh()->environments()->where('role', Environment::ROLE_STAGING)->exists())->toBeTrue();
});
