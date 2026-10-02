<?php

use App\Jobs\CloneOdooStagingJob;
use App\Livewire\Project\AddEmpty;
use App\Livewire\Project\Edit;
use App\Livewire\Project\Show;
use App\Livewire\Team\Member;
use App\Livewire\Team\Member as TeamMember;
use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\OdooEnvironmentBranch;
use App\Models\OdooProfile;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Services\AdminCreationQuota;
use App\Support\OdooStaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
});

it('does not create an odoo profile or staging when a normal project is created', function () {
    expect(OdooProfile::query()->count())->toBe(0);
    expect(OdooStaging::canCreateStagingEnvironment($this->project))->toBeFalse();
    expect($this->project->environments()->pluck('name')->all())->toBe(['production']);
});

it('starts an odoo project without a github account', function () {
    Livewire::test(Edit::class, ['project_uuid' => $this->project->uuid])
        ->set('odooVersion', '20')
        ->call('enableOdoo')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    expect($this->project->odooProfile()->first())->not->toBeNull()
        ->and($this->project->odooProfile()->first()->odoo_version)->toBe('20')
        ->and($this->project->environments()->pluck('name')->all())->toBe(['production']);
});

it('saves an odoo profile without creating an environment or deploying', function () {
    $this->project->enableOdoo('20');

    $profile = $this->project->odooProfile()->first();

    expect($profile)->not->toBeNull()
        ->and($profile->odoo_version)->toBe('20')
        ->and($profile->max_staging_environments)->toBe(1)
        ->and($profile->unlimited_staging_environments)->toBeFalse()
        ->and($this->project->services()->count())->toBe(0)
        ->and($this->project->environments()->pluck('name')->all())->toBe(['production']);
});

it('does not count production as a staging environment', function () {
    $admin = User::factory()->create();
    $admin->teams()->attach($this->team, ['role' => 'admin', 'max_staging_branches' => 1]);
    $this->actingAs($admin);

    $this->project->enableOdoo('18');
    $this->project->createNextStagingEnvironment();

    expect(OdooStaging::stagingEnvironments($this->project)->pluck('name')->all())->toBe(['staging-1']);
    expect($this->project->canCreateStagingEnvironment())->toBeFalse();
    expect($this->project->environments()->where('name', 'production')->exists())->toBeTrue();
    expect(app(AdminCreationQuota::class)->stagingLaunchUsage($admin->id, $this->team->id))->toBe(1);
});

it('updates the odoo version without creating another staging environment', function () {
    $this->project->enableOdoo('18');

    Livewire::test(Edit::class, ['project_uuid' => $this->project->uuid])
        ->set('odooVersion', '19')
        ->call('enableOdoo')
        ->assertHasNoErrors();

    expect($this->project->odooProfile()->first()->odoo_version)->toBe('19');
    expect(OdooStaging::stagingEnvironments($this->project)->count())->toBe(0);
});

it('reuses an existing staging environment and does not create another', function () {
    Environment::factory()->create([
        'name' => 'Staging',
        'project_id' => $this->project->id,
    ]);

    $this->project->enableOdoo('18', 3, false);

    expect($this->project->environments()->count())->toBe(2);
    expect(OdooStaging::stagingEnvironments($this->project)->count())->toBe(1);
    expect($this->project->environments()->where('name', 'staging-1')->exists())->toBeFalse();
    expect($this->project->createNextStagingEnvironment()->name)->toBe('staging-2');
});

it('rejects a staging environment past the user limit on every project', function () {
    $admin = User::factory()->create();
    $admin->teams()->attach($this->team, ['role' => 'admin', 'max_staging_branches' => 1]);
    $this->actingAs($admin);
    $this->project->enableOdoo('18', 5, true);
    $this->project->createNextStagingEnvironment();

    expect($this->project->canCreateStagingEnvironment())->toBeFalse();
    expect(fn () => $this->project->createNextStagingEnvironment())->toThrow(RuntimeException::class);

    $other = Project::factory()->create(['team_id' => $this->team->id]);
    $other->enableOdoo('18', 5, true);

    expect($other->environments()->where('name', 'staging-1')->exists())->toBeFalse();
    expect(OdooStaging::stagingEnvironments($this->project)->pluck('name')->all())->toBe(['staging-1']);
});

it('allows more staging environments when the user has no staging limit', function () {
    $admin = User::factory()->create();
    $admin->teams()->attach($this->team, ['role' => 'admin']);
    $this->actingAs($admin);
    $this->project->enableOdoo('18', 1, false);
    $this->project->createNextStagingEnvironment();
    $this->project->createNextStagingEnvironment();

    expect(OdooStaging::stagingEnvironments($this->project)->count())->toBe(2);
    expect($this->project->canCreateStagingEnvironment())->toBeTrue();
});

it('clones production into one staging and keeps a single production', function () {
    $this->project->enableOdoo('20', 2, false);

    Livewire::test(Edit::class, ['project_uuid' => $this->project->uuid])
        ->call('cloneProductionAsStaging')
        ->assertDispatched('success');

    expect($this->project->environments()->whereRaw('LOWER(name) = ?', ['production'])->count())->toBe(1);
    expect($this->project->environments()->orderBy('name')->pluck('name')->all())->toBe([
        'production',
        'staging-1',
    ]);
    expect($this->project->services()->count())->toBe(0);
});

it('does not create a staging when the user limit is already full', function () {
    $admin = User::factory()->create();
    $admin->teams()->attach($this->team, ['role' => 'admin', 'max_staging_branches' => 1]);
    $this->actingAs($admin);
    $this->project->enableOdoo('18');
    $this->project->createNextStagingEnvironment();

    expect(fn () => $this->project->cloneProductionAsStaging())->toThrow(RuntimeException::class);
    expect($this->project->environments()->whereRaw('LOWER(name) = ?', ['production'])->count())->toBe(1);
    expect($this->project->environments()->pluck('name')->all())->toEqualCanonicalizing(['production', 'staging-1']);
});

it('does not let a member clone production into a staging', function () {
    $this->project->enableOdoo('18', 2, false);
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Edit::class, ['project_uuid' => $this->project->uuid])
        ->call('cloneProductionAsStaging')
        ->assertDispatched('error');

    expect($this->project->environments()->where('name', 'staging-2')->exists())->toBeFalse();
    expect($this->project->environments()->whereRaw('LOWER(name) = ?', ['production'])->count())->toBe(1);
});

it('rejects a negative staging limit and keeps it off the project page', function () {
    $admin = User::factory()->create();
    $admin->teams()->attach($this->team, ['role' => 'admin']);

    Livewire::test(Edit::class, ['project_uuid' => $this->project->uuid])
        ->assertDontSee('Allow unlimited staging environments');

    Livewire::test(Member::class, ['member' => $admin])
        ->set('maxStagingBranches', -1)
        ->call('saveCreationLimits')
        ->assertHasErrors(['maxStagingBranches']);
});

it('rejects an unsupported odoo version', function () {
    Livewire::test(Edit::class, ['project_uuid' => $this->project->uuid])
        ->set('odooVersion', '21')
        ->call('enableOdoo')
        ->assertHasErrors(['odooVersion']);

    expect(OdooProfile::query()->count())->toBe(0);
    expect($this->project->environments()->count())->toBe(1);
});

it('does not let a member enable or change odoo settings', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Edit::class, ['project_uuid' => $this->project->uuid])
        ->set('odooVersion', '18')
        ->call('enableOdoo')
        ->assertDispatched('error');

    expect(OdooProfile::query()->count())->toBe(0);
    expect($this->project->environments()->where('name', 'staging-1')->exists())->toBeFalse();
    expect($member->can('create', Server::class))->toBeFalse();
    expect($member->can('create', S3Storage::class))->toBeFalse();

    Livewire::test(Edit::class, ['project_uuid' => $this->project->uuid])
        ->call('enableOdoo')
        ->assertDispatched('error')
        ->call('cloneProductionAsStaging')
        ->assertDispatched('error')
        ->call('loadOdooRepositories')
        ->assertDispatched('error')
        ->call('loadOdooBranches')
        ->assertDispatched('error')
        ->call('saveOdooGit')
        ->assertDispatched('error');
});

it('lets only the owner attach a github account and a staging limit to a user', function () {
    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) str()->uuid(),
        'name' => 'odoo-github',
        'private_key' => 'test-key',
        'is_git_related' => true,
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $githubApp = GithubApp::create([
        'name' => 'Acme GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 123,
        'installation_id' => 456,
        'private_key_id' => $keyId,
        'webhook_secret' => 'odoo-hook',
        'team_id' => $this->team->id,
        'is_public' => false,
    ]);
    $admin = User::factory()->create();
    $admin->teams()->attach($this->team, ['role' => 'admin']);

    Livewire::test(TeamMember::class, ['member' => $admin])
        ->set('maxStagingBranches', 1)
        ->set('githubAppId', $githubApp->id)
        ->call('saveCreationLimits')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $pivot = DB::table('team_user')->where('user_id', $admin->id)->where('team_id', $this->team->id)->first();
    expect((int) $pivot->max_staging_branches)->toBe(1)
        ->and((int) $pivot->github_app_id)->toBe($githubApp->id);

    $this->actingAs($admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(TeamMember::class, ['member' => $admin])
        ->assertDontSee('Save limits')
        ->set('maxStagingBranches', 9)
        ->set('githubAppId', null)
        ->call('saveCreationLimits')
        ->assertDispatched('error');

    expect((int) DB::table('team_user')->where('user_id', $admin->id)->value('max_staging_branches'))->toBe(1)
        ->and((int) DB::table('team_user')->where('user_id', $admin->id)->value('github_app_id'))->toBe($githubApp->id);

    $this->project->enableOdoo('18');

    Livewire::test(Edit::class, ['project_uuid' => $this->project->uuid])
        ->assertSet('odooGithubConnected', true)
        ->assertSet('odooGithubAppId', $githubApp->id);
});

it('removes the odoo profile when the project is deleted', function () {
    $this->project->enableOdoo('18');
    $projectId = $this->project->id;

    $this->project->delete();

    expect(OdooProfile::query()->where('project_id', $projectId)->exists())->toBeFalse();
});

it('asks for the odoo version and github while creating the project', function () {
    Livewire::test(AddEmpty::class)
        ->assertSee('Service')
        ->assertDontSee('Connect GitHub')
        ->set('name', 'Cliente Odoo')
        ->set('description', 'demo')
        ->set('service', 'odoo')
        ->assertSee('Odoo version')
        ->assertSee('Connect GitHub')
        ->set('odooVersion', '20')
        ->set('connectGithub', false)
        ->call('submit')
        ->assertRedirect();

    $project = Project::query()->where('name', 'Cliente Odoo')->first();
    expect($project->odooProfile->odoo_version)->toBe('20')
        ->and($project->environments()->pluck('name')->all())->toBe(['production']);

    $before = GithubApp::query()->count();
    Livewire::test(AddEmpty::class)
        ->set('name', 'Cliente Git')
        ->set('description', 'demo')
        ->set('service', 'odoo')
        ->set('odooVersion', '19')
        ->set('connectGithub', true)
        ->call('submit')
        ->assertRedirect();

    $connected = Project::query()->where('name', 'Cliente Git')->first();
    expect($connected->odooProfile->odoo_version)->toBe('19')
        ->and(session('from.back'))->toBe('project.resource.index')
        ->and(session('from.parameters.project_uuid'))->toBe($connected->uuid)
        ->and(GithubApp::query()->count())->toBe($before + 1)
        ->and(Application::query()->count())->toBe(0);
});

it('keeps an odoo environment on the project and lets a member open odoo', function () {
    $this->project->enableOdoo('20');
    $production = $this->project->environments()->where('name', 'production')->first();
    $service = Service::factory()->create([
        'environment_id' => $production->id,
        'name' => 'odoo-production',
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
    ]);
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->assertDontSee('Open environment')
        ->assertDontSee('>Clone<')
        ->call('selectEnvironment', $production->uuid)
        ->assertSee('Open environment')
        ->assertDontSee('Clone')
        ->assertSee('Open Odoo')
        ->assertSee('production')
        ->assertSee(route('project.service.configuration', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $production->uuid,
            'service_uuid' => $service->uuid,
        ], false));

    $this->get(route('project.resource.index', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $production->uuid,
    ]))->assertOk()->assertSee('production');

    $this->get(route('project.service.configuration', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $production->uuid,
        'service_uuid' => $service->uuid,
    ]))->assertOk();
});

it('lists environments once and clones production into one staging', function () {
    $this->project->enableOdoo('20');
    $production = $this->project->environments()->where('name', 'production')->first();
    expect(file_get_contents(resource_path('views/livewire/project/show.blade.php')))
        ->not->toContain('project.odoo-summary')
        ->toContain('openCloneWizard')
        ->toContain('closeCloneWizard')
        ->not->toContain('Clone to staging')
        ->toContain('cloneToStaging')
        ->toContain('stagingBranch')
        ->toContain('Search branches')
        ->toContain('Mounting the environment')
        ->toContain('Copying the service')
        ->toContain('Cloning the branch')
        ->toContain('Waiting until Odoo can be opened')
        ->toContain('neutralizes that copy')
        ->toContain('refreshCloneProgress');
    expect(file_get_contents(resource_path('views/livewire/project/resource/index.blade.php')))
        ->toContain('installOdoo')
        ->toContain('This environment only runs Odoo.');

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->call('selectEnvironment', $production->uuid)
        ->set('cloneAddons', 'empty')
        ->call('cloneToStaging')
        ->assertRedirect();

    expect($this->project->environments()->pluck('name')->sort()->values()->all())->toBe(['production', 'staging-1'])
        ->and(Application::query()->count())->toBe(0)
        ->and($this->project->environments()->where('name', 'staging-1')->first()->odooBranch)->toBeNull();
});

it('copies the production service into the staging clone', function () {
    $this->project->enableOdoo('20');
    $production = $this->project->environments()->where('name', 'production')->first();
    Service::factory()->create([
        'environment_id' => $production->id,
        'name' => 'odoo-production',
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
        'server_id' => null,
    ]);

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->call('selectEnvironment', $production->uuid)
        ->set('cloneAddons', 'copy')
        ->call('cloneToStaging')
        ->assertRedirect();

    $staging = $this->project->environments()->where('name', 'staging-1')->first();
    $copy = $staging->services()->first();

    expect($copy)->not->toBeNull()
        ->and($copy->docker_compose_raw)->toContain('odoo:20')
        ->and($copy->environment_id)->toBe($staging->id)
        ->and($production->services()->count())->toBe(1);
});

it('does not create a staging environment when the branch is already used', function () {
    $this->project->enableOdoo('20');
    $production = $this->project->environments()->where('name', 'production')->first();
    OdooEnvironmentBranch::query()->create([
        'environment_id' => $production->id,
        'git_branch' => 'main',
    ]);
    $githubApp = GithubApp::create([
        'name' => 'Acme GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 123,
        'installation_id' => 456,
        'team_id' => $this->team->id,
        'is_public' => false,
    ]);
    $this->project->odooProfile->update(['git_repository' => 'acme/odoo', 'github_app_id' => $githubApp->id]);

    Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])
        ->call('selectEnvironment', $production->uuid)
        ->set('cloneBranches', ['develop'])
        ->set('stagingBranch', 'main')
        ->call('cloneToStaging')
        ->assertDispatched('error');

    expect($this->project->environments()->pluck('name')->all())->toBe(['production']);
});

it('opens an odoo environment directly on its service', function () {
    $this->project->enableOdoo('20');
    $production = $this->project->environments()->where('name', 'production')->first();
    $service = Service::factory()->create([
        'environment_id' => $production->id,
        'name' => 'odoo-production',
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
        'server_id' => null,
    ]);

    $html = Livewire::test(Show::class, ['project_uuid' => $this->project->uuid])->html();

    expect($html)->toContain('/service/'.$service->uuid);
});

it('lets the root owner clone staging when the queue has no session', function () {
    $rootTeam = Team::factory()->make(['name' => 'Root']);
    $rootTeam->id = 0;
    $rootTeam->save();

    $root = User::factory()->make([
        'name' => 'Root',
        'email' => 'root-owner@example.test',
    ]);
    $root->id = 0;
    $root->save();
    $root->teams()->attach($rootTeam->id, ['role' => 'owner']);

    $project = Project::factory()->create(['team_id' => $rootTeam->id, 'created_by' => $root->id]);
    $project->enableOdoo('20');
    $production = $project->environments()->where('name', 'production')->first();

    Auth::logout();

    (new CloneOdooStagingJob(
        $project->id,
        $production->uuid,
        'staging-1',
        'empty',
        'odoo-clone-root',
        0,
    ))->handle();

    $status = Cache::get('odoo-clone-root');

    expect($status['error'] ?? null)->toBeNull()
        ->and($status['redirect']['name'] ?? null)->toBe('project.show')
        ->and($status['url'] ?? null)->toBeNull()
        ->and($project->environments()->pluck('name')->sort()->values()->all())->toBe(['production', 'staging-1']);
});

it('sends an odoo project away from the generic resource catalog', function () {
    $this->project->enableOdoo('20');
    $production = $this->project->environments()->where('name', 'production')->first();

    $this->get(route('project.resource.create', [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $production->uuid,
    ]))->assertRedirect(route('project.show', ['project_uuid' => $this->project->uuid]));
});
