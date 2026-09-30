<?php

namespace App\Jobs;

use App\Actions\Service\RestartServiceApplication;
use App\Models\OdooEnvironmentBranch;
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

        try {
            $environment = $branch->environment()->with('services.applications')->first();
            foreach ($environment?->services ?? [] as $service) {
                if (! $service->supportsOdooJupyter()) {
                    continue;
                }
                $odoo = $service->applications->first(fn ($application): bool => $application->name === 'odoo');
                if ($odoo !== null) {
                    RestartServiceApplication::run($odoo);
                }
            }
            $branch->update(['status' => 'idle']);
        } catch (Throwable $e) {
            $branch->update(['status' => 'failed']);
            throw $e;
        }
    }
}
