<?php

namespace App\Livewire\Project;

use App\Domain\Odoo\OdooAbilities;
use App\Domain\Odoo\OdooStaging;
use App\Jobs\MigrateOdooShJob;
use App\Models\Environment;
use App\Models\OdooMigration;
use App\Models\Project;
use App\Support\OdooGit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class OdooMigrate extends Component
{
    use AuthorizesRequests;
    use WithFileUploads;

    public Project $project;

    public string $gitRepository = '';

    public string $environmentId = '';

    public $databaseDump = null;

    public $filestoreArchive = null;

    public $addonsZip = null;

    public ?int $migrationId = null;

    public function mount(string $project_uuid): void
    {
        $this->project = Project::query()->where('uuid', $project_uuid)->firstOrFail();
        $this->authorize('update', $this->project);
        $this->guardAccess();

        $profile = $this->project->odooProfile;
        abort_unless($profile !== null, 404);
        $this->gitRepository = (string) ($profile->git_repository ?? '');

        $production = $this->project->environments()
            ->whereRaw('LOWER(name) = ?', ['production'])
            ->first();
        $this->environmentId = $production ? (string) $production->id : '';

        $latest = OdooMigration::query()
            ->where('project_id', $this->project->id)
            ->latest('id')
            ->first();
        $this->migrationId = $latest?->id;
        if ($latest?->environment_id) {
            $this->environmentId = (string) $latest->environment_id;
        }
    }

    public function saveRepository(): void
    {
        $this->authorize('update', $this->project);
        $this->guardAccess();
        $this->validate([
            'gitRepository' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/'],
            'environmentId' => ['required', 'integer', Rule::in($this->allowedEnvironmentIds())],
        ]);

        $migration = $this->draft();
        $migration->update([
            'git_repository' => trim($this->gitRepository) !== '' ? trim($this->gitRepository) : null,
            'odoo_version' => $this->project->odooProfile?->odoo_version,
            'environment_id' => (int) $this->environmentId,
        ]);
        $this->migrationId = $migration->id;
        $this->dispatch('success', __('Repository and target environment saved.'));
    }

    public function uploadFiles(): void
    {
        $this->authorize('update', $this->project);
        $this->guardAccess();
        $this->validate([
            'environmentId' => ['required', 'integer', Rule::in($this->allowedEnvironmentIds())],
            'databaseDump' => ['required', 'file', 'max:51200'],
            'filestoreArchive' => ['required', 'file', 'max:51200'],
            'addonsZip' => ['nullable', 'file', 'max:51200'],
        ]);

        $migration = $this->draft();
        $base = 'odoo-migrations/'.$this->project->uuid.'/'.$migration->uuid;
        $databasePath = $this->databaseDump->storeAs($base, 'database.dump', 'local');
        $filestorePath = $this->filestoreArchive->storeAs($base, 'filestore.tar.gz', 'local');
        $addonsPath = null;
        $addonsName = null;
        if ($this->addonsZip !== null) {
            $addonsPath = $this->addonsZip->storeAs($base, 'addons.zip', 'local');
            $addonsName = $this->addonsZip->getClientOriginalName();
        }

        $migration->update([
            'status' => 'files_ready',
            'environment_id' => (int) $this->environmentId,
            'database_disk_path' => $databasePath,
            'filestore_disk_path' => $filestorePath,
            'database_original_name' => $this->databaseDump->getClientOriginalName(),
            'filestore_original_name' => $this->filestoreArchive->getClientOriginalName(),
            'addons_disk_path' => $addonsPath,
            'addons_original_name' => $addonsName,
            'git_repository' => trim($this->gitRepository) !== '' ? trim($this->gitRepository) : $migration->git_repository,
            'error' => null,
        ]);
        $this->migrationId = $migration->id;
        $this->databaseDump = null;
        $this->filestoreArchive = null;
        $this->addonsZip = null;
        $this->dispatch('success', __('Migration files uploaded.'));
    }

    public function start(): void
    {
        $this->authorize('update', $this->project);
        $this->guardAccess();
        $this->validate([
            'environmentId' => ['required', 'integer', Rule::in($this->allowedEnvironmentIds())],
        ]);

        $migration = $this->draft();
        if (! $migration->hasFiles()) {
            $this->dispatch('error', __('Upload both the database dump and the filestore archive first.'));

            return;
        }

        $migration->update([
            'status' => 'queued',
            'error' => null,
            'environment_id' => (int) $this->environmentId,
            'git_repository' => trim($this->gitRepository) !== '' ? trim($this->gitRepository) : $migration->git_repository,
            'user_id' => auth()->id(),
        ]);
        MigrateOdooShJob::dispatch($migration->id);
        $this->migrationId = $migration->id;
        $this->dispatch('success', __('Migration queued. We restore dump and filestore onto the selected environment.'));
    }

    public function connectGithub(): mixed
    {
        $this->authorize('update', $this->project);
        $this->guardAccess();
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
        $this->project->loadMissing('team.getodooPlan', 'odooProfile', 'environments.services');
        $migration = $this->migrationId
            ? OdooMigration::query()->find($this->migrationId)
            : null;
        $githubReady = OdooGit::connectedApps($this->project->team_id)->isNotEmpty();

        return view('livewire.project.odoo-migrate', [
            'migration' => $migration,
            'githubReady' => $githubReady,
            'plan' => $this->project->team?->getodooPlan,
            'environmentChoices' => $this->environmentChoices(),
        ]);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function environmentChoices(): array
    {
        return $this->project->environments
            ->filter(fn (Environment $environment): bool => strcasecmp($environment->name, 'production') === 0
                || OdooStaging::isStagingName($environment->name))
            ->sortBy(fn (Environment $environment): array => [
                strcasecmp($environment->name, 'production') === 0 ? 0 : 1,
                $environment->name,
            ])
            ->values()
            ->map(function (Environment $environment): array {
                $service = $environment->services->first(fn ($row): bool => $row->supportsOdooJupyter());
                $label = $environment->name;
                if ($service === null) {
                    $label .= ' · '.__('launch Odoo first');
                }

                return ['value' => (string) $environment->id, 'label' => $label];
            })
            ->all();
    }

    /**
     * @return list<int>
     */
    private function allowedEnvironmentIds(): array
    {
        return collect($this->environmentChoices())->pluck('value')->map(fn ($id): int => (int) $id)->all();
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
            'environment_id' => filled($this->environmentId) ? (int) $this->environmentId : null,
            'team_id' => $this->project->team_id,
            'user_id' => auth()->id(),
            'status' => 'draft',
            'odoo_version' => $this->project->odooProfile?->odoo_version,
            'git_repository' => trim($this->gitRepository) !== '' ? trim($this->gitRepository) : null,
        ]);
        $this->migrationId = $migration->id;

        return $migration;
    }

    private function guardAccess(): void
    {
        $user = auth()->user();
        if ($user === null || ! OdooAbilities::allows($user, (int) $this->project->team_id, 'odoo.project.update')) {
            abort(403);
        }
    }
}
