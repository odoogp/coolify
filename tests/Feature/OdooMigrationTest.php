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
        ->assertSee(__('Want automatic backups?'))
        ->assertSee(__('Contact an advisor to add backups to your plan and protect this project.'))
        ->assertDontSee($this->plan->name)
        ->call('createBackup')
        ->assertDispatched('error');

    expect(fn () => (new CreateOdooBackupJob($production->id))->handle())
        ->toThrow(RuntimeException::class);
});

it('lets the instance owner create a manual backup when the plan has none', function () {
    Queue::fake();
    Team::factory()->create(['id' => 0]);
    $this->owner->teams()->attach(0, ['role' => 'owner']);
    $this->owner->unsetRelation('teams');
    $this->actingAs($this->owner->fresh(['teams']));
    session(['currentTeam' => $this->team]);
    $this->plan->update(['backup_frequency' => 'none']);
    $production = $this->project->environments()->whereRaw('LOWER(name) = ?', ['production'])->first();

    expect($this->owner->fresh(['teams'])->isInstanceOwner())->toBeTrue();

    Livewire::test(OdooBackups::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $production->uuid,
    ])
        ->assertOk()
        ->assertSee(__('Create Backup'))
        ->assertDontSee(__('Want automatic backups?'))
        ->call('createBackup')
        ->assertDispatched('success');

    Queue::assertPushed(CreateOdooBackupJob::class, fn (CreateOdooBackupJob $job): bool => $job->environmentId === $production->id && $job->bypassPlanRestriction === true);
});

it('lists branch backups and queues restore for a complete odoo zip', function () {
    Queue::fake();
    $production = $this->project->environments()->whereRaw('LOWER(name) = ?', ['production'])->first();
    $backup = OdooBackup::query()->create([
        'environment_id' => $production->id,
        'status' => 'complete',
        'kind' => OdooBackup::KIND_AUTOMATIC,
        'filename' => '/data/coolify/backups/odoo/test/odoo-production.zip',
        'filesize' => 2048,
    ]);

    Livewire::test(OdooBackups::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $production->uuid,
    ])
        ->assertOk()
        ->assertSee(__('Create Backup'))
        ->assertSee(__('Complete'))
        ->assertSee($production->name)
        ->assertSee('odoo-backups-table-grid', false)
        ->assertSee('odoo-backup-mobile-meta', false)
        ->call('restore', $backup->id)
        ->assertDispatched('success');

    Queue::assertPushed(RestoreOdooBackupJob::class, fn (RestoreOdooBackupJob $job): bool => $job->odooBackupId === $backup->id);
});

it('stacks odoo backup rows on small screens instead of a wide horizontal table', function () {
    $view = file_get_contents(resource_path('views/livewire/project/odoo-backups.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($view)
        ->toContain('odoo-backups-table-grid')
        ->toContain('odoo-backup-mobile-meta')
        ->toContain('odoo-backup-actions')
        ->not->toContain('min-w-[44rem]');

    expect($css)
        ->toContain('.odoo-backups-table-grid')
        ->toContain('.odoo-backups-table-grid .odoo-backup-mobile-meta')
        ->toContain('grid-column: 1 / -1');
});

it('translates in-progress backup status on the branch list', function () {
    $production = $this->project->environments()->whereRaw('LOWER(name) = ?', ['production'])->first();
    OdooBackup::query()->create([
        'environment_id' => $production->id,
        'status' => 'pending',
        'kind' => OdooBackup::KIND_MANUAL,
    ]);

    Livewire::test(OdooBackups::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $production->uuid,
    ])
        ->assertOk()
        ->assertSee(__('In progress'))
        ->assertSee(__('Saving…'))
        ->assertDontSee('pending');
});

it('offers a single odoo zip download for a complete backup', function () {
    $production = $this->project->environments()->whereRaw('LOWER(name) = ?', ['production'])->first();
    $backup = OdooBackup::query()->create([
        'environment_id' => $production->id,
        'status' => 'complete',
        'kind' => OdooBackup::KIND_MANUAL,
        'filename' => '/data/coolify/backups/odoo/test/odoo-production.zip',
        'filesize' => 4096,
    ]);

    Livewire::test(OdooBackups::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $production->uuid,
    ])
        ->assertOk()
        ->assertSee(__('Download'))
        ->assertDontSee(__('Database dump'))
        ->assertDontSee(__('Filestore archive'))
        ->assertSee(route('download.odoo-backup', ['backupId' => $backup->id]), false);
});

it('lets the instance owner delete an odoo backup', function () {
    Team::factory()->create(['id' => 0]);
    $this->owner->teams()->attach(0, ['role' => 'owner']);
    $this->owner->unsetRelation('teams');
    $this->actingAs($this->owner->fresh(['teams']));
    session(['currentTeam' => $this->team]);
    $production = $this->project->environments()->whereRaw('LOWER(name) = ?', ['production'])->first();
    $backup = OdooBackup::query()->create([
        'environment_id' => $production->id,
        'status' => 'complete',
        'kind' => OdooBackup::KIND_MANUAL,
        'filename' => '/data/coolify/backups/odoo/test/odoo-delete-me.zip',
        'filesize' => 128,
    ]);

    Livewire::test(OdooBackups::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $production->uuid,
    ])
        ->assertOk()
        ->assertSee(__('Delete'))
        ->call('deleteBackup', $backup->id)
        ->assertDispatched('success');

    expect(OdooBackup::query()->whereKey($backup->id)->exists())->toBeFalse();
});

it('prunes odoo zip backups older than the plan retention window', function () {
    $production = $this->project->environments()->whereRaw('LOWER(name) = ?', ['production'])->first();
    $old = OdooBackup::query()->create([
        'environment_id' => $production->id,
        'status' => 'complete',
        'kind' => OdooBackup::KIND_AUTOMATIC,
        'filename' => '/data/coolify/backups/odoo/test/odoo-old.zip',
        'filesize' => 10,
    ]);
    OdooBackup::query()->whereKey($old->id)->update([
        'created_at' => now()->subDays(30),
        'updated_at' => now()->subDays(30),
    ]);
    $fresh = OdooBackup::query()->create([
        'environment_id' => $production->id,
        'status' => 'complete',
        'kind' => OdooBackup::KIND_AUTOMATIC,
        'filename' => '/data/coolify/backups/odoo/test/odoo-fresh.zip',
        'filesize' => 10,
    ]);

    \App\Support\OdooZipBackup::prune($production, 14);

    expect(OdooBackup::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(OdooBackup::query()->whereKey($fresh->id)->exists())->toBeTrue();
});

it('shows the team plan on project settings', function () {
    Livewire::test(\App\Livewire\Project\Edit::class, ['project_uuid' => $this->project->uuid])
        ->assertOk()
        ->assertSee(__('Plan'))
        ->assertSee($this->plan->name)
        ->assertSee(__('The plan is set when the team signs up. Contact an advisor to change it.'));
});

it('puts migrate on project settings and not on the project show page', function () {
    Livewire::test(\App\Livewire\Project\Edit::class, ['project_uuid' => $this->project->uuid])
        ->assertOk()
        ->assertSee(__('Migrate'))
        ->assertSee(route('project.odoo.migrate', ['project_uuid' => $this->project->uuid]), false);

    Livewire::test(\App\Livewire\Project\Show::class, ['project_uuid' => $this->project->uuid])
        ->assertOk()
        ->assertDontSee(__('Import a database dump and filestore into a branch of this project.'))
        ->assertDontSeeHtml('href="'.route('project.odoo.migrate', ['project_uuid' => $this->project->uuid]).'"');
});
