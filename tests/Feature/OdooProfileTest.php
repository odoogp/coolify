<?php

use App\Livewire\Project\Edit;
use App\Livewire\Team\Member as TeamMember;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\OdooProfile;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\AdminCreationQuota;
use App\Support\OdooStaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    expect(fn () => $this->project->cloneProductionAsStaging())->toThrow(\RuntimeException::class);
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

    Livewire::test(\App\Livewire\Team\Member::class, ['member' => $admin])
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
