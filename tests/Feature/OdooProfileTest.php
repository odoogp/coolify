<?php

use App\Livewire\Project\Edit;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\OdooProfile;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Support\OdooStaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

it('enables an odoo profile and an empty first staging environment without deploying', function () {
    Livewire::test(Edit::class, ['project_uuid' => $this->project->uuid])
        ->set('odooVersion', '20')
        ->call('enableOdoo')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $profile = $this->project->odooProfile()->first();
    $staging = $this->project->environments()->where('name', 'staging-1')->first();

    expect($profile)->not->toBeNull()
        ->and($profile->odoo_version)->toBe('20')
        ->and($profile->max_staging_environments)->toBe(1)
        ->and($profile->unlimited_staging_environments)->toBeFalse()
        ->and($staging)->not->toBeNull()
        ->and($staging->isEmpty())->toBeTrue()
        ->and($staging->services()->count())->toBe(0)
        ->and(OdooStaging::stagingEnvironments($this->project)->pluck('name')->all())->toBe(['staging-1'])
        ->and($this->project->environments()->orderBy('name')->pluck('name')->all())->toBe(['production', 'staging-1']);
});

it('does not count production as a staging environment', function () {
    $this->project->enableOdoo('18', 1, false);

    expect(OdooStaging::stagingEnvironments($this->project)->pluck('name')->all())->toBe(['staging-1']);
    expect($this->project->canCreateStagingEnvironment())->toBeFalse();
    expect($this->project->environments()->where('name', 'production')->exists())->toBeTrue();
});

it('updates the odoo version without creating another staging environment', function () {
    $this->project->enableOdoo('18');

    Livewire::test(Edit::class, ['project_uuid' => $this->project->uuid])
        ->set('odooVersion', '19')
        ->set('maxStagingEnvironments', 1)
        ->call('enableOdoo')
        ->assertHasNoErrors();

    expect($this->project->odooProfile()->first()->odoo_version)->toBe('19');
    expect(OdooStaging::stagingEnvironments($this->project)->count())->toBe(1);
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

it('rejects a staging environment past the configured limit', function () {
    $this->project->enableOdoo('18', 2, false);
    $this->project->createNextStagingEnvironment();

    expect($this->project->canCreateStagingEnvironment())->toBeFalse();
    expect(fn () => $this->project->createNextStagingEnvironment())->toThrow(RuntimeException::class);
    expect($this->project->environments()->orderBy('name')->pluck('name')->all())->toBe([
        'production',
        'staging-1',
        'staging-2',
    ]);
});

it('allows more staging environments when the limit is unlimited', function () {
    $this->project->enableOdoo('18', 1, true);
    $this->project->createNextStagingEnvironment();
    $this->project->createNextStagingEnvironment();

    expect(OdooStaging::stagingEnvironments($this->project)->count())->toBe(3);
    expect($this->project->canCreateStagingEnvironment())->toBeTrue();
    expect($this->project->odooProfile->max_staging_environments)->toBe(1);
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
        'staging-2',
    ]);
    expect($this->project->services()->count())->toBe(0);
});

it('does not create a staging when the limit is already full', function () {
    $this->project->enableOdoo('18', 1, false);

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

it('rejects a negative staging limit', function () {
    Livewire::test(Edit::class, ['project_uuid' => $this->project->uuid])
        ->set('maxStagingEnvironments', -1)
        ->call('enableOdoo')
        ->assertHasErrors(['maxStagingEnvironments']);

    expect(OdooProfile::query()->count())->toBe(0);
    expect($this->project->environments()->pluck('name')->all())->toBe(['production']);
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
        ->set('maxStagingEnvironments', 4)
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

it('removes the odoo profile when the project is deleted', function () {
    $this->project->enableOdoo('18');
    $projectId = $this->project->id;

    $this->project->delete();

    expect(OdooProfile::query()->where('project_id', $projectId)->exists())->toBeFalse();
});
