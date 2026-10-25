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

it('creates a staging catalog launch environment before any production service', function () {
    $empty = $this->project->productionEnvironment();
    expect($empty->isEmpty())->toBeTrue();

    $staging = $this->project->createCatalogLaunchEnvironment(Environment::ROLE_STAGING, 'n8n');

    expect($staging->isStaging())->toBeTrue()
        ->and($this->project->fresh()->environments()->where('role', Environment::ROLE_STAGING)->count())->toBe(1)
        ->and($this->project->fresh()->productionEnvironment()?->isEmpty())->toBeTrue();
});

it('reuses the empty production shell when catalog launch asks for production', function () {
    $empty = $this->project->productionEnvironment();

    $target = $this->project->createCatalogLaunchEnvironment(Environment::ROLE_PRODUCTION, 'n8n');

    expect($target->is($empty))->toBeTrue()
        ->and($target->isProduction())->toBeTrue();
});

it('clones non-odoo production services into a new staging environment', function () {
    $production = $this->project->productionEnvironment();
    Service::factory()->create([
        'environment_id' => $production->id,
        'name' => 'n8n-production',
        'docker_compose_raw' => "services:\n  n8n:\n    image: n8nio/n8n\n",
        'server_id' => null,
        'service_type' => 'n8n',
    ]);

    Auth::logout();

    (new CloneEnvironmentStagingJob(
        $this->project->id,
        $production->uuid,
        'env-clone-generic',
        $this->user->id,
        Environment::ROLE_STAGING,
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

it('clones into a second production when the clone target role is production', function () {
    $production = $this->project->productionEnvironment();
    Service::factory()->create([
        'environment_id' => $production->id,
        'name' => 'n8n-production',
        'docker_compose_raw' => "services:\n  n8n:\n    image: n8nio/n8n\n",
        'server_id' => null,
        'service_type' => 'n8n',
    ]);

    (new CloneEnvironmentStagingJob(
        $this->project->id,
        $production->uuid,
        'env-clone-as-production',
        $this->user->id,
        Environment::ROLE_PRODUCTION,
    ))->handle();

    $status = Cache::get('env-clone-as-production');
    $productions = $this->project->fresh()->environments()->where('role', Environment::ROLE_PRODUCTION)->get();

    expect($status['error'] ?? null)->toBeNull()
        ->and($status['done'] ?? false)->toBeTrue()
        ->and($productions)->toHaveCount(2)
        ->and($this->project->environments()->where('role', Environment::ROLE_STAGING)->exists())->toBeFalse();
});

it('shows clone on any environment with services and asks for the next role', function () {
    $production = $this->project->productionEnvironment();
    Service::factory()->create([
        'environment_id' => $production->id,
        'name' => 'app-production',
        'docker_compose_raw' => "services:\n  web:\n    image: nginx\n",
        'server_id' => null,
        'service_type' => 'nginx',
    ]);

    $html = Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->call('selectEnvironment', $production->uuid)
        ->html();

    expect($html)->toContain(__('Clone'));

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->call('selectEnvironment', $production->uuid)
        ->call('openCloneWizard')
        ->assertSet('showCloneWizard', true)
        ->assertSet('cloneEnvironmentRole', Environment::ROLE_STAGING)
        ->set('cloneEnvironmentRole', Environment::ROLE_STAGING)
        ->call('cloneToStaging');

    expect($this->project->fresh()->environments()->where('role', Environment::ROLE_STAGING)->exists())->toBeTrue();
});

it('asks production or staging on project catalog new', function () {
    $html = Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])->html();

    expect($html)->toContain(__('Environment type'))
        ->and($html)->toContain(__('Production'))
        ->and($html)->toContain(__('Staging'));
});
