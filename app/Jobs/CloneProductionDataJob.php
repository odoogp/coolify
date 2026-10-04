<?php

namespace App\Jobs;

use App\Models\Environment;
use App\Models\OdooAuditLog;
use App\Models\OdooBackup;
use App\Support\OdooAddons;
use App\Domain\Odoo\OdooStaging;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class CloneProductionDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $stagingEnvironmentId, public int $backupId)
    {
        $this->onQueue('high');
    }

    /**
     * @return array{source_volume: string, target_volume: string, read_only_source: true}
     */
    public function plan(): array
    {
        $staging = Environment::query()->with('project', 'services')->findOrFail($this->stagingEnvironmentId);
        $backup = OdooBackup::query()->findOrFail($this->backupId);

        if (! OdooStaging::isStagingName($staging->name)) {
            throw new RuntimeException('Data clone only writes a staging environment.');
        }

        if ($backup->environment_id !== $staging->id || $backup->status !== 'complete') {
            throw new RuntimeException('Data clone requires a complete backup of staging first.');
        }

        $production = $staging->project->environments()->whereRaw('LOWER(name) = ?', ['production'])->first();
        $source = $production?->services()->first();
        $target = $staging->services->first();
        if ($source === null || $target === null) {
            throw new RuntimeException('Data clone needs an Odoo service on production and on staging.');
        }

        $plan = OdooAddons::dataClonePlan($source, $target);
        if ($plan['source_volume'] === $plan['target_volume'] || ! str_contains($plan['target_volume'], $target->uuid)) {
            throw new RuntimeException('Data clone refused to write the production volume.');
        }

        return $plan;
    }

    public function handle(): void
    {
        $plan = $this->plan();
        $staging = Environment::query()->findOrFail($this->stagingEnvironmentId);
        OdooAuditLog::write(auth()->id(), $staging->project_id, $staging->id, 'odoo.data.clone', 'finished', $plan);
    }
}
