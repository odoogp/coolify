<?php

use App\Actions\Odoo\ProvisionOdooEnvironment;
use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Jobs\CloneProductionDataJob;
use App\Jobs\CreateOdooBackupJob;
use App\Jobs\RestoreOdooBackupJob;
use App\Jobs\SyncOdooAddonsJob;
use App\Jobs\SyncStagingBranchJob;
use App\Livewire\Team\Member as TeamMember;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\GithubApp;
use App\Models\InstanceSettings;
use App\Models\OdooAuditLog;
use App\Models\OdooBackup;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Support\OdooAbilities;
use App\Support\OdooAddons;
use App\Support\OdooGit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'is_api_enabled' => true,
    ]));

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->owner->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->project->enableOdoo('18');
    $this->project->createNextStagingEnvironment();

    $this->githubApp = GithubApp::create([
        'name' => 'Acme GitHub',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'app_id' => 123,
        'installation_id' => 456,
        'team_id' => $this->team->id,
        'is_public' => false,
    ]);

    $production = $this->project->environments()->where('name', 'production')->first();
    $staging = $this->project->environments()->where('name', 'staging-1')->first();
    OdooGit::assign($this->project, $this->githubApp, 'acme/odoo', 99, ['main', 'develop'], [
        $production->id => 'main',
        $staging->id => 'develop',
    ]);

    $key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $key->id,
    ]);
    $this->destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
});

it('provisions isolated services with different domains and addon volumes', function () {
    $production = $this->project->environments()->where('name', 'production')->first();
    $staging = $this->project->environments()->where('name', 'staging-1')->first();
    $staging->odooBranch->update([
        'odoo_version' => '20',
        'workers' => 2,
        'jupyter_enabled' => true,
        'addons_path' => '/mnt/extra-addons',
    ]);

    $productionService = ProvisionOdooEnvironment::run($production, $this->destination, 'erp.cliente.com');
    $stagingService = ProvisionOdooEnvironment::run($staging, $this->destination, 'https://staging.cliente.com');

    expect($productionService->uuid)->not->toBe($stagingService->uuid)
        ->and($productionService->environment_id)->not->toBe($stagingService->environment_id)
        ->and(OdooAddons::extraAddonsVolume($productionService))->not->toBe(OdooAddons::extraAddonsVolume($stagingService))
        ->and(OdooAddons::filestoreVolume($productionService))->toContain($productionService->uuid)
        ->and($stagingService->jupyter_enabled)->toBeTrue()
        ->and($stagingService->docker_compose_raw)->toContain('odoo:20')
        ->and($stagingService->docker_compose_raw)->toContain('--workers=2')
        ->and($production->odooBranch->fresh()->domain)->toBe('https://erp.cliente.com')
        ->and($staging->odooBranch->fresh()->addons_application_id)->not->toBeNull()
        ->and(Application::query()->where('is_odoo_addons', true)->count())->toBe(2);

    expect(fn () => ProvisionOdooEnvironment::run($staging->fresh(), $this->destination, 'erp.cliente.com'))
        ->toThrow(RuntimeException::class);
});

it('queues an odoo addon application on the addon job and keeps deployment statuses', function () {
    Queue::fake();
    $production = $this->project->environments()->where('name', 'production')->first();
    ProvisionOdooEnvironment::run($production, $this->destination, 'erp.cliente.com');
    $application = Application::query()->where('is_odoo_addons', true)->first();

    queue_application_deployment($application, 'deploy-odoo-1', commit: 'abc');

    Queue::assertPushed(SyncOdooAddonsJob::class);
    Queue::assertNotPushed(ApplicationDeploymentJob::class);
    $deployment = ApplicationDeploymentQueue::query()->where('application_id', $application->id)->first();
    expect($deployment->status)->toBe(ApplicationDeploymentStatus::IN_PROGRESS->value);

    $production->services()->first()->applications()->delete();
    (new SyncOdooAddonsJob($deployment->id))->handle();

    expect($deployment->fresh()->status)->toBe(ApplicationDeploymentStatus::FINISHED->value)
        ->and($deployment->fresh()->logs)->toContain($production->odooBranch->fresh()->service_id ? OdooAddons::extraAddonsVolume($production->services()->first()) : '');
});

it('marks a backup complete only when database and filestore both succeeded', function () {
    $staging = $this->project->environments()->where('name', 'staging-1')->first();
    $backup = (new CreateOdooBackupJob($staging->id))->handle();
    expect($backup->status)->toBe('pending');
    expect(OdooAuditLog::query()->where('action', 'odoo.backup.create')->exists())->toBeTrue();

    $database = ScheduledDatabaseBackupExecution::query()->create([
        'status' => 'success',
        'scheduled_database_backup_id' => 1,
    ]);
    $schedule = ScheduledVolumeBackup::query()->create([
        'backupable_type' => \App\Models\Environment::class,
        'backupable_id' => $staging->id,
        'team_id' => $this->team->id,
        'frequency' => '0 0 * * *',
    ]);
    $volume = ScheduledVolumeBackupExecution::query()->create([
        'status' => 'failed',
        'scheduled_volume_backup_id' => $schedule->id,
    ]);
    $backup->syncLegs($database->id, $volume->id);
    expect($backup->fresh()->status)->toBe('partial');

    $volume->update(['status' => 'success']);
    $backup->syncLegs($database->id, $volume->id);
    expect($backup->fresh()->status)->toBe('complete');

    expect(fn () => (new RestoreOdooBackupJob($backup->id))->plan())->not->toThrow(RuntimeException::class);

    $backup->update(['status' => 'pending']);
    expect(fn () => (new RestoreOdooBackupJob($backup->id))->plan())->toThrow(RuntimeException::class);
});

it('refuses to clone production data onto production or without a complete staging backup', function () {
    $production = $this->project->environments()->where('name', 'production')->first();
    $staging = $this->project->environments()->where('name', 'staging-1')->first();
    ProvisionOdooEnvironment::run($production, $this->destination, 'erp.cliente.com');
    ProvisionOdooEnvironment::run($staging, $this->destination, 'staging.cliente.com');

    $backup = OdooBackup::query()->create([
        'environment_id' => $staging->id,
        'status' => 'pending',
    ]);
    expect(fn () => (new CloneProductionDataJob($staging->id, $backup->id))->plan())->toThrow(RuntimeException::class);
    expect(fn () => (new CloneProductionDataJob($production->id, $backup->id))->plan())->toThrow(RuntimeException::class);

    $backup->update(['status' => 'complete']);
    $plan = (new CloneProductionDataJob($staging->id, $backup->id))->plan();
    expect($plan['read_only_source'])->toBeTrue()
        ->and($plan['target_volume'])->toContain($staging->services()->first()->uuid)
        ->and($plan['source_volume'])->toContain($production->services()->first()->uuid)
        ->and($plan['target_volume'])->not->toContain($production->services()->first()->uuid);
    expect(SyncStagingBranchJob::mergePayload('develop', 'main'))->not->toHaveKey('force');
    expect(fn () => (new SyncStagingBranchJob($production->id))->handle())->toThrow(RuntimeException::class);
});

it('keeps server and s3 away from members even when an odoo ability is granted', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);

    expect($member->can('create', Server::class))->toBeFalse()
        ->and($member->can('create', S3Storage::class))->toBeFalse()
        ->and(OdooAbilities::allows($member, $this->team->id, 'odoo.staging.deploy'))->toBeFalse()
        ->and(OdooAbilities::allows($member, $this->team->id, 'odoo.project.view'))->toBeTrue()
        ->and(OdooAbilities::allows($member, $this->team->id, 'server.create'))->toBeFalse();

    Livewire::test(TeamMember::class, ['member' => $member])
        ->set('odooAbilities', ['odoo.staging.deploy', 'server.create'])
        ->call('saveOdooAbilities')
        ->assertDispatched('success');

    expect(OdooAbilities::allows($member, $this->team->id, 'odoo.staging.deploy'))->toBeTrue()
        ->and(OdooAbilities::allows($member, $this->team->id, 'server.create'))->toBeFalse()
        ->and($member->can('create', Server::class))->toBeFalse();

    $this->actingAs($member);
    Livewire::test(TeamMember::class, ['member' => $member])
        ->set('odooAbilities', ['odoo.production.deploy'])
        ->call('saveOdooAbilities')
        ->assertDispatched('error');
    expect(OdooAbilities::allows($member->fresh(), $this->team->id, 'odoo.production.deploy'))->toBeFalse();
});

it('exposes the odoo project through the api using the same rules', function () {
    $token = $this->owner->createToken('odoo', ['read', 'write']);
    $token->accessToken->forceFill(['team_id' => $this->team->id])->save();

    $this->getJson('/api/v1/projects/'.$this->project->uuid.'/odoo', [
        'Authorization' => 'Bearer '.$token->plainTextToken,
    ])->assertOk()->assertJsonPath('environments.0.name', 'production');

    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $memberToken = $member->createToken('odoo-member', ['read']);
    $memberToken->accessToken->forceFill(['team_id' => $this->team->id])->save();

    $this->getJson('/api/v1/projects/'.$this->project->uuid.'/odoo', [
        'Authorization' => 'Bearer '.$memberToken->plainTextToken,
    ])->assertOk();
});
