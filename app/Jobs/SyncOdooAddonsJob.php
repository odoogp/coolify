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
use App\Support\OdooGit;
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
        $deployment = $this->deployment();
        $branch = $this->branch($deployment);
        if ($branch === null) {
            $this->failDeployment($deployment, 'No environment branch matched this update, so nothing was cloned.');

            return;
        }

        $environment = $branch->environment()->with('project', 'services')->first();
        $service = $this->service($environment, $branch);
        $modulesSynced = false;

        try {
            $branch->update(['status' => 'updating']);

            if ($service === null) {
                throw new \RuntimeException('No Odoo service is linked to this environment branch, so addons cannot be cloned.');
            }

            $volume = OdooAddons::extraAddonsVolume($service);
            $repository = (string) ($environment?->project?->odooProfile?->git_repository ?: 'repository');
            $gitBranch = (string) ($branch->git_branch ?: 'branch');

            $deployment?->update([
                'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
            ]);
            $deployment?->addLogEntry("Updating modules for {$repository}@{$gitBranch}");
            $deployment?->addLogEntry("Cloning into {$volume}");

            OdooGit::ensureAddonsApplication($environment, $service);
            $deployment?->addLogEntry('Running git clone into the addons volume…');
            OdooGit::cloneIntoService($service);
            $modulesSynced = true;
            $deployment?->addLogEntry('Clone finished. Modules are on disk.');

            try {
                $deployment?->addLogEntry('Reinstalling owner package if configured…');
                OdooGit::reinstallOwnerPackageIntoService($service);
            } catch (Throwable $ownerError) {
                $deployment?->addLogEntry(
                    'Owner package warning (modules already synced): '.$this->failureReason($ownerError),
                    'stderr'
                );
            }

            // Critical path is done: mark success before restart so a hung Apps refresh
            // or worker timeout cannot flip a good sync to Failed.
            $this->markFinished($deployment, $environment, $volume, $branch);

            try {
                $deployment?->addLogEntry('Restarting Odoo and refreshing the Apps list…');
                (new RestartOdooBranchJob($branch->id))->handle();
                $deployment?->addLogEntry('Odoo restart finished.');
            } catch (Throwable $restartError) {
                $deployment?->addLogEntry(
                    'Modules synced, but restart/refresh warning: '.$this->failureReason($restartError),
                    'stderr'
                );
                $branch->update(['status' => 'idle']);
            }
        } catch (Throwable $e) {
            if ($modulesSynced) {
                $deployment?->addLogEntry(
                    'Modules synced, but a later step warned: '.$this->failureReason($e),
                    'stderr'
                );
                $this->markFinished(
                    $deployment,
                    $environment,
                    OdooAddons::extraAddonsVolume($service),
                    $branch,
                );

                return;
            }

            $this->failDeployment($deployment, $this->failureReason($e), $branch, $environment, $e);
            throw $e;
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        $deployment = $this->deployment();
        if (! $deployment instanceof ApplicationDeploymentQueue) {
            return;
        }

        $deployment->refresh();
        if ($deployment->status === ApplicationDeploymentStatus::FINISHED->value) {
            return;
        }

        // Worker killed after clone: modules are already on disk — do not show Failed.
        if ($this->logsSayModulesSynced($deployment)) {
            $deployment->addLogEntry(
                'Worker stopped after modules were already on disk. Marking this update as successful.',
            );
            $this->markFinished($deployment, null, null, $this->branch($deployment));

            return;
        }

        $reason = $exception instanceof Throwable
            ? $this->failureReason($exception)
            : 'The update worker stopped before finishing (timeout or process kill).';

        $this->failDeployment($deployment, $reason, $this->branch($deployment));
    }

    private function deployment(): ?ApplicationDeploymentQueue
    {
        if ($this->application_deployment_queue_id <= 0) {
            return null;
        }

        return ApplicationDeploymentQueue::query()->find($this->application_deployment_queue_id);
    }

    private function markFinished(
        ?ApplicationDeploymentQueue $deployment,
        ?Environment $environment,
        ?string $volume,
        ?OdooEnvironmentBranch $branch,
    ): void {
        if ($deployment instanceof ApplicationDeploymentQueue) {
            $deployment->refresh();
            if ($deployment->status !== ApplicationDeploymentStatus::FINISHED->value) {
                if ($volume !== null && ! str($deployment->logs ?? '')->contains('Module update finished')) {
                    $deployment->addLogEntry("Module update finished for {$volume}");
                }
                $deployment->update([
                    'status' => ApplicationDeploymentStatus::FINISHED->value,
                    'finished_at' => $deployment->finished_at ?? now(),
                ]);
            }
        }

        $branch?->update(['status' => 'idle']);
        $environment ??= $branch?->environment;
        if ($environment !== null) {
            $this->record($environment, 'odoo.addons.synced', 'finished', [
                'volume' => $volume,
            ]);
        }
    }

    private function logsSayModulesSynced(ApplicationDeploymentQueue $deployment): bool
    {
        $logs = (string) ($deployment->logs ?? '');

        return str($logs)->contains('Clone finished')
            || str($logs)->contains('Modules are on disk')
            || str($logs)->contains('Module update finished');
    }

    private function failDeployment(
        ?ApplicationDeploymentQueue $deployment,
        string $reason,
        ?OdooEnvironmentBranch $branch = null,
        ?Environment $environment = null,
        ?Throwable $exception = null,
    ): void {
        $reason = trim($reason) !== ''
            ? $reason
            : 'The module update failed without a detailed error message.';

        if ($deployment instanceof ApplicationDeploymentQueue) {
            $deployment->refresh();
            if ($deployment->status === ApplicationDeploymentStatus::FINISHED->value) {
                return;
            }
            $alreadyLogged = str($deployment->logs ?? '')->contains($reason);
            if (! $alreadyLogged) {
                $deployment->addLogEntry('Update failed: '.$reason, 'stderr');
            }
            $deployment->update([
                'status' => ApplicationDeploymentStatus::FAILED->value,
                'finished_at' => $deployment->finished_at ?? now(),
            ]);
        }

        $branch?->update(['status' => 'failed']);
        $environment ??= $branch?->environment;
        $this->record(
            $environment,
            'odoo.addons.synced',
            'failed',
            ['message' => $reason, 'exception' => $exception ? $exception::class : null],
        );
    }

    private function failureReason(Throwable $exception): string
    {
        $parts = [];
        $current = $exception;
        while ($current instanceof Throwable) {
            $message = trim($current->getMessage());
            if ($message !== '') {
                $parts[] = $message;
            }
            $current = $current->getPrevious();
        }

        $reason = trim(implode(' — ', array_unique($parts)));

        return $reason !== ''
            ? $reason
            : $exception::class.' was thrown without a message.';
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
