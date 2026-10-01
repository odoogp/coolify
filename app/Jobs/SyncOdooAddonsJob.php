<?php

namespace App\Jobs;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\OdooAuditLog;
use App\Models\OdooEnvironmentBranch;
use App\Models\Service;
use App\Notifications\Internal\GeneralNotification;
use App\Support\OdooAddons;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncOdooAddonsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $application_deployment_queue_id = 0,
        public int $odooEnvironmentBranchId = 0,
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $deployment = $this->application_deployment_queue_id > 0
            ? ApplicationDeploymentQueue::query()->find($this->application_deployment_queue_id)
            : null;
        $branch = $this->branch($deployment);
        if ($branch === null) {
            return;
        }

        $environment = $branch->environment()->with('project', 'services')->first();
        $service = $this->service($environment, $branch);
        $volume = $service === null ? null : OdooAddons::extraAddonsVolume($service);

        try {
            if ($deployment !== null) {
                $deployment->update([
                    'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
                    'logs' => $volume === null ? '' : 'git pull '.$volume,
                ]);
            }

            if ($service !== null) {
                \App\Support\OdooGit::cloneIntoService($service);
            }

            (new RestartOdooBranchJob($branch->id))->handle();

            $deployment?->update(['status' => ApplicationDeploymentStatus::FINISHED->value]);
            $this->record($environment, 'odoo.addons.synced', 'finished', [
                'volume' => $service === null ? null : OdooAddons::extraAddonsVolume($service),
            ]);
        } catch (Throwable $e) {
            $deployment?->update(['status' => ApplicationDeploymentStatus::FAILED->value]);
            $branch->update(['status' => 'failed']);
            $this->record($environment, 'odoo.addons.synced', 'failed', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function branch(?ApplicationDeploymentQueue $deployment): ?OdooEnvironmentBranch
    {
        if ($this->odooEnvironmentBranchId > 0) {
            return OdooEnvironmentBranch::query()->find($this->odooEnvironmentBranchId);
        }

        $application = $deployment?->application;
        if ($application === null) {
            return null;
        }

        return OdooEnvironmentBranch::query()
            ->where('environment_id', $application->environment_id)
            ->where('git_branch', $application->git_branch)
            ->first();
    }

    private function service(?Environment $environment, OdooEnvironmentBranch $branch): ?Service
    {
        if ($branch->service_id !== null) {
            return Service::query()->find($branch->service_id);
        }

        return $environment?->services->first(fn (Service $service): bool => $service->supportsOdooJupyter());
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(?Environment $environment, string $action, string $result, array $metadata): void
    {
        OdooAuditLog::write(
            auth()->id(),
            $environment?->project_id,
            $environment?->id,
            $action,
            $result,
            $metadata,
        );

        try {
            $environment?->project?->team?->notify(new GeneralNotification(product_name().': '.$action.' '.$result));
        } catch (Throwable) {
        }
    }
}
