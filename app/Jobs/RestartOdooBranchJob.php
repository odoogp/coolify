<?php

namespace App\Jobs;

use App\Actions\Service\RestartServiceApplication;
use App\Models\OdooEnvironmentBranch;
use App\Models\Service;
use App\Support\GpshNotices;
use App\Support\OdooGit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class RestartOdooBranchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $odooEnvironmentBranchId)
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $branch = OdooEnvironmentBranch::query()->find($this->odooEnvironmentBranchId);
        if ($branch === null) {
            return;
        }

        $restarted = false;

        try {
            $environment = $branch->environment()->with('services.applications')->first();
            foreach ($environment?->services ?? [] as $service) {
                if (! $service instanceof Service || ! $service->supportsOdooJupyter()) {
                    continue;
                }
                $odoo = $service->applications->first(fn ($application): bool => $application->name === 'odoo');
                if ($odoo === null) {
                    continue;
                }
                // Always restart Odoo after a modules sync so the new addons load.
                GpshNotices::announceRestarting($service);
                RestartServiceApplication::run($odoo);
                $restarted = true;
                // Give the container a moment, then refresh Apps (same as Update Apps List).
                // Refresh is best-effort: never undo a successful restart/module sync.
                try {
                    sleep(5);
                    OdooGit::refreshAppsList($service);
                } catch (Throwable) {
                    // Modules are already on disk and Odoo was restarted.
                }
            }
            $branch->update(['status' => 'idle']);
        } catch (Throwable $e) {
            // If docker restart already ran, modules are live — do not mark the branch failed.
            $branch->update(['status' => $restarted ? 'idle' : 'failed']);
            if ($restarted) {
                return;
            }
            throw $e;
        }
    }
}
