<?php

use App\Jobs\MigrateOdooShJob;
use App\Livewire\Project\OdooMigrate;
use App\Models\GetOdooPlan;
use App\Models\InstanceSettings;
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
        ->assertSee(__('Migrate from Odoo.sh'))
        ->assertSee(__('Dump and filestore'));
});

it('refuses the migration wizard when the plan does not include it', function () {
    $this->plan->update(['includes_migration' => false]);

    $this->get(route('project.odoo.migrate', ['project_uuid' => $this->project->uuid]))
        ->assertForbidden();
});

it('uploads dump and filestore then queues the migrate job', function () {
    Queue::fake();
    Storage::fake('local');

    $component = Livewire::test(OdooMigrate::class, ['project_uuid' => $this->project->uuid])
        ->set('gitRepository', 'acme/odoo-addons')
        ->call('saveRepository')
        ->assertHasNoErrors()
        ->set('databaseDump', UploadedFile::fake()->create('db.dump', 100))
        ->set('filestoreArchive', UploadedFile::fake()->create('filestore.tar.gz', 100))
        ->call('uploadFiles')
        ->assertHasNoErrors()
        ->call('start')
        ->assertDispatched('success');

    $migration = OdooMigration::query()->where('project_id', $this->project->id)->first();
    expect($migration)->not->toBeNull()
        ->and($migration->status)->toBe('queued')
        ->and($migration->git_repository)->toBe('acme/odoo-addons')
        ->and($migration->hasFiles())->toBeTrue();

    Queue::assertPushed(MigrateOdooShJob::class, fn (MigrateOdooShJob $job): bool => $job->migrationId === $migration->id);
    expect($component->get('migrationId'))->toBe($migration->id);
});

it('lists backup frequency on the plan included items', function () {
    $labels = collect($this->plan->includedItems())->pluck('label')->all();
    expect($labels)->toContain(__('Migration help (GitHub, repository, dump + filestore)'))
        ->and($labels)->toContain(__('Automatic Odoo backups'));
});
