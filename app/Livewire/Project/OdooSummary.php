<?php

namespace App\Livewire\Project;

use App\Jobs\CloneProductionDataJob;
use App\Jobs\CreateOdooBackupJob;
use App\Jobs\SyncStagingBranchJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\OdooBackup;
use App\Models\Project;
use App\Support\OdooAbilities;
use App\Support\OdooStaging;
use App\Support\OdooVersion;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class OdooSummary extends Component
{
    use AuthorizesRequests;

    public Project $project;

    public function mount(Project $project): void
    {
        $this->authorize('view', $project);
        $this->project = $project;
    }

    public function deploy(int $environmentId): void
    {
        $environment = $this->environment($environmentId);
        $ability = OdooStaging::isStagingName($environment->name) ? 'odoo.staging.deploy' : 'odoo.production.deploy';
        $this->guard($ability);
        $application = Application::query()->find($environment->odooBranch?->addons_application_id);
        if ($application === null) {
            $this->dispatch('error', __('This environment has no Odoo service yet.'));

            return;
        }

        queue_application_deployment(
            application: $application,
            deployment_uuid: new_public_id(),
            commit: 'HEAD',
        );
        $this->dispatch('success', __('Deployment queued.'));
    }

    public function syncStaging(int $environmentId): void
    {
        $environment = $this->environment($environmentId);
        $this->guard('odoo.staging.sync');
        SyncStagingBranchJob::dispatch($environment->id);
        $this->dispatch('success', __('Staging sync queued.'));
    }

    public function backup(int $environmentId): void
    {
        $environment = $this->environment($environmentId);
        $this->guard('odoo.backup.create');
        CreateOdooBackupJob::dispatch($environment->id);
        $this->dispatch('success', __('Backup queued.'));
    }

    public function cloneData(int $environmentId): void
    {
        $environment = $this->environment($environmentId);
        $this->guard('odoo.backup.restore');
        $backup = OdooBackup::query()
            ->where('environment_id', $environment->id)
            ->where('status', 'complete')
            ->latest('id')
            ->first();
        if ($backup === null) {
            $this->dispatch('error', __('Data clone requires a complete backup of staging first.'));

            return;
        }

        CloneProductionDataJob::dispatch($environment->id, $backup->id);
        $this->dispatch('success', __('Data clone queued.'));
    }

    public function render()
    {
        $this->project->load('odooProfile', 'environments.odooBranch');
        $user = auth()->user();
        $rows = $this->project->odooProfile === null ? [] : $this->project->environments
            ->filter(fn (Environment $environment): bool => strcasecmp($environment->name, 'production') === 0 || OdooStaging::isStagingName($environment->name))
            ->map(function (Environment $environment) use ($user): array {
                $applicationId = $environment->odooBranch?->addons_application_id;
                $latest = $applicationId === null ? null : ApplicationDeploymentQueue::query()
                    ->where('application_id', $applicationId)
                    ->latest('id')
                    ->first();

                return [
                    'id' => $environment->id,
                    'name' => $environment->name,
                    'domain' => $environment->odooBranch?->domain,
                    'version' => $environment->odooBranch?->odoo_version,
                    'workers' => $environment->odooBranch?->workers,
                    'jupyter' => (bool) $environment->odooBranch?->jupyter_enabled,
                    'addons_path' => $environment->odooBranch?->addons_path,
                    'branch' => $environment->odooBranch?->git_branch,
                    'status' => $latest?->status,
                    'staging' => OdooStaging::isStagingName($environment->name),
                ];
            })
            ->values()
            ->all();

        return view('livewire.project.odoo-summary', [
            'rows' => $rows,
            'versions' => OdooVersion::SUPPORTED,
            'canUpdate' => $user !== null && OdooAbilities::allows($user, (int) $this->project->team_id, 'odoo.project.update'),
        ]);
    }

    private function environment(int $environmentId): Environment
    {
        return $this->project->environments()->whereKey($environmentId)->firstOrFail();
    }

    private function guard(string $ability): void
    {
        $user = auth()->user();
        if ($user === null || ! OdooAbilities::allows($user, (int) $this->project->team_id, $ability)) {
            abort(403);
        }
    }
}
