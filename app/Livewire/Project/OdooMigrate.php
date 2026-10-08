<?php

namespace App\Livewire\Project;

use App\Domain\Odoo\OdooAbilities;
use App\Jobs\MigrateOdooShJob;
use App\Models\OdooMigration;
use App\Models\Project;
use App\Support\OdooGit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithFileUploads;

class OdooMigrate extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public Project $project;

    public string $gitRepository = '';

    public $databaseDump = null;

    public $filestoreArchive = null;

    public ?int $migrationId = null;

    public function mount(string $project_uuid): void
    {
        $this->project = Project::query()->where('uuid', $project_uuid)->firstOrFail();
        $this->authorize('update', $this->project);
        $this->guardPlan();

        $profile = $this->project->odooProfile;
        $this->gitRepository = (string) ($profile?->git_repository ?? '');

        $latest = OdooMigration::query()
            ->where('project_id', $this->project->id)
            ->latest('id')
            ->first();
        $this->migrationId = $latest?->id;
    }

    public function saveRepository(): void
    {
        $this->authorize('update', $this->project);
        $this->guardPlan();
        $this->validate([
            'gitRepository' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/'],
        ]);

        $migration = $this->draft();
        $migration->update([
            'git_repository' => trim($this->gitRepository) !== '' ? trim($this->gitRepository) : null,
            'odoo_version' => $this->project->odooProfile?->odoo_version,
        ]);
        $this->migrationId = $migration->id;
        $this->dispatch('success', __('Repository saved for this migration.'));
    }

    public function uploadFiles(): void
    {
        $this->authorize('update', $this->project);
        $this->guardPlan();
        $this->validate([
            'databaseDump' => ['required', 'file', 'max:51200'],
            'filestoreArchive' => ['required', 'file', 'max:51200'],
        ]);

        $migration = $this->draft();
        $base = 'odoo-migrations/'.$this->project->uuid.'/'.$migration->uuid;
        $databasePath = $this->databaseDump->storeAs($base, 'database.dump', 'local');
        $filestorePath = $this->filestoreArchive->storeAs($base, 'filestore.tar.gz', 'local');

        $migration->update([
            'status' => 'files_ready',
            'database_disk_path' => $databasePath,
            'filestore_disk_path' => $filestorePath,
            'database_original_name' => $this->databaseDump->getClientOriginalName(),
            'filestore_original_name' => $this->filestoreArchive->getClientOriginalName(),
            'git_repository' => trim($this->gitRepository) !== '' ? trim($this->gitRepository) : $migration->git_repository,
            'error' => null,
        ]);
        $this->migrationId = $migration->id;
        $this->databaseDump = null;
        $this->filestoreArchive = null;
        $this->dispatch('success', __('Dump and filestore uploaded.'));
    }

    public function start(): void
    {
        $this->authorize('update', $this->project);
        $this->guardPlan();

        $migration = $this->draft();
        if (! $migration->hasFiles()) {
            $this->dispatch('error', __('Upload both the database dump and the filestore archive first.'));

            return;
        }

        $migration->update([
            'status' => 'queued',
            'error' => null,
            'git_repository' => trim($this->gitRepository) !== '' ? trim($this->gitRepository) : $migration->git_repository,
            'user_id' => auth()->id(),
        ]);
        MigrateOdooShJob::dispatch($migration->id);
        $this->migrationId = $migration->id;
        $this->dispatch('success', __('Migration queued. Keep this project open; we will restore dump and filestore on production.'));
    }

    public function connectGithub(): mixed
    {
        $this->authorize('update', $this->project);
        $this->guardPlan();
        $githubApp = OdooGit::beginConnect($this->project, 'project.odoo.migrate', [
            'project_uuid' => $this->project->uuid,
        ]);
        if (filled($githubApp->installation_id) && filled($githubApp->private_key_id)) {
            $this->dispatch('success', __('GitHub is already connected.'));

            return null;
        }
        if (filled($githubApp->app_id)) {
            return redirect()->away(getInstallationPath($githubApp));
        }

        return redirect()->route('source.github.show', ['github_app_uuid' => $githubApp->uuid]);
    }

    public function render()
    {
        $this->project->loadMissing('team.getodooPlan', 'odooProfile');
        $migration = $this->migrationId
            ? OdooMigration::query()->find($this->migrationId)
            : null;
        $githubReady = OdooGit::connectedApps($this->project->team_id)->isNotEmpty();

        return view('livewire.project.odoo-migrate', [
            'migration' => $migration,
            'githubReady' => $githubReady,
            'plan' => $this->project->team?->getodooPlan,
        ]);
    }

    private function draft(): OdooMigration
    {
        if ($this->migrationId) {
            $existing = OdooMigration::query()
                ->whereKey($this->migrationId)
                ->where('project_id', $this->project->id)
                ->first();
            if ($existing !== null && ! in_array($existing->status, ['complete', 'failed'], true)) {
                return $existing;
            }
        }

        $migration = OdooMigration::query()->create([
            'project_id' => $this->project->id,
            'team_id' => $this->project->team_id,
            'user_id' => auth()->id(),
            'status' => 'draft',
            'odoo_version' => $this->project->odooProfile?->odoo_version,
            'git_repository' => trim($this->gitRepository) !== '' ? trim($this->gitRepository) : null,
        ]);
        $this->migrationId = $migration->id;

        return $migration;
    }

    private function guardPlan(): void
    {
        $user = auth()->user();
        if ($user === null || ! OdooAbilities::allows($user, (int) $this->project->team_id, 'odoo.project.update')) {
            abort(403);
        }

        $plan = $this->project->team?->getodooPlan;
        if ($plan === null || ! $plan->includes_migration) {
            abort(403, __('This plan does not include migration help.'));
        }
    }
}
