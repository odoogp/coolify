<?php

use App\Jobs\CreateOdooBackupJob;
use App\Jobs\MigrateOdooShJob;
use App\Jobs\RestoreOdooBackupJob;
use App\Livewire\Project\OdooBackups;
use App\Livewire\Project\OdooMigrate;
use App\Models\GetOdooPlan;
use App\Models\InstanceSettings;
use App\Models\OdooBackup;
use App\Models\OdooMigration;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Support\GetOdooBackupFrequency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $this->owner = User::factory()->create();
    $this->plan = GetOdooPlan::query()->create([
        'name' => 'Migrate plan',
        'price' => 0,
        'currency' => 'USD',
        'is_active' => true,
        'includes_migration' => true,
        'backup_frequency' => GetOdooBackupFrequency::DAILY,
        'backup_retention_days' => 7,
    ]);
    $this->team = Team::factory()->create(['getodoo_plan_id' => $this->plan->id]);
    $this->owner->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->project->enableOdoo('18');
});

it('opens the migration wizard when the plan includes migration', function () {
    Livewire::test(OdooMigrate::class, ['project_uuid' => $this->project->uuid])
        ->assertOk()
        ->assertSee(__('Migrate'))
        ->assertSee(__('Modules zip (optional)'));
});

it('opens the migrator for project admins without requiring the plan flag', function () {
    $this->plan->update(['includes_migration' => false]);

    Livewire::test(OdooMigrate::class, ['project_uuid' => $this->project->uuid])
        ->assertOk()
        ->assertSee(__('Restore into'));
});

it('uploads dump, filestore and modules zip then queues the migrate job', function () {
    Queue::fake();
    Storage::fake('local');

    $component = Livewire::test(OdooMigrate::class, ['project_uuid' => $this->project->uuid])
        ->set('gitRepository', '')
        ->set('databaseDump', UploadedFile::fake()->create('db.dump', 100))
        ->set('filestoreArchive', UploadedFile::fake()->create('filestore.tar.gz', 100))
        ->set('addonsZip', UploadedFile::fake()->create('addons.zip', 100))
        ->call('uploadFiles')
        ->assertHasNoErrors()
        ->call('start')
        ->assertDispatched('success');

    $migration = OdooMigration::query()->where('project_id', $this->project->id)->first();
    expect($migration)->not->toBeNull()
        ->and($migration->status)->toBe('queued')
        ->and($migration->hasFiles())->toBeTrue()
        ->and($migration->hasAddonsZip())->toBeTrue();

    Queue::assertPushed(MigrateOdooShJob::class, fn (MigrateOdooShJob $job): bool => $job->migrationId === $migration->id);
    expect($component->get('migrationId'))->toBe($migration->id);
});

it('offers a brand-new environment and creates it when starting migration', function () {
    Queue::fake();
    Storage::fake('local');

    $component = Livewire::test(OdooMigrate::class, ['project_uuid' => $this->project->uuid])
        ->assertSee(__('New environment (:name)', ['name' => 'staging-1']))
        ->set('environmentId', OdooMigrate::NEW_ENVIRONMENT)
        ->set('databaseDump', UploadedFile::fake()->create('db.dump', 100))
        ->set('filestoreArchive', UploadedFile::fake()->create('filestore.tar.gz', 100))
        ->call('uploadFiles')
        ->assertHasNoErrors()
        ->call('start')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $staging = $this->project->fresh()->environments()->where('name', 'staging-1')->first();
    $migration = OdooMigration::query()->where('project_id', $this->project->id)->first();

    expect($staging)->not->toBeNull()
        ->and($migration)->not->toBeNull()
        ->and((int) $migration->environment_id)->toBe((int) $staging->id)
        ->and($component->get('environmentId'))->toBe((string) $staging->id);
});

it('lists backup frequency on the plan included items', function () {
    $labels = collect($this->plan->includedItems())->pluck('label')->all();
    expect($labels)->toContain(__('Migration help (GitHub, repository, dump + filestore)'))
        ->and($labels)->toContain(__('Automatic Odoo backups'));
});

it('shows plan-driven odoo backups for a branch and refuses create when the plan has none', function () {
    $this->plan->update(['backup_frequency' => 'none']);
    $production = $this->project->environments()->whereRaw('LOWER(name) = ?', ['production'])->first();

    Livewire::test(OdooBackups::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $production->uuid,
    ])
        ->assertOk()
        ->assertSee(__('Backups are not included on this plan'))
        ->call('createBackup')
        ->assertDispatched('error');

    expect(fn () => (new CreateOdooBackupJob($production->id))->handle())
        ->toThrow(RuntimeException::class);
});

it('lists branch backups and queues restore for a complete pair', function () {
    Queue::fake();
    $production = $this->project->environments()->whereRaw('LOWER(name) = ?', ['production'])->first();
    $backup = OdooBackup::query()->create([
        'environment_id' => $production->id,
        'status' => 'complete',
        'kind' => OdooBackup::KIND_AUTOMATIC,
    ]);

    Livewire::test(OdooBackups::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $production->uuid,
    ])
        ->assertOk()
        ->assertSee(__('Create Backup'))
        ->assertSee(__('Plan policy'))
        ->assertSee($production->name)
        ->call('restore', $backup->id)
        ->assertDispatched('success');

    Queue::assertPushed(RestoreOdooBackupJob::class, fn (RestoreOdooBackupJob $job): bool => $job->odooBackupId === $backup->id);
});
