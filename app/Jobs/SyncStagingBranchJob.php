<?php

namespace App\Jobs;

use App\Models\Environment;
use App\Models\OdooAuditLog;
use App\Domain\Odoo\OdooStaging;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class SyncStagingBranchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $stagingEnvironmentId)
    {
        $this->onQueue('high');
    }

    /**
     * @return array{base: string, head: string, commit_message: string}
     */
    public static function mergePayload(string $base, string $head): array
    {
        return [
            'base' => $base,
            'head' => $head,
            'commit_message' => 'Sync staging from production',
        ];
    }

    public function handle(): void
    {
        $staging = Environment::query()->with('project.odooProfile', 'odooBranch')->find($this->stagingEnvironmentId);
        $production = $staging?->project?->environments()->whereRaw('LOWER(name) = ?', ['production'])->first();
        $production?->load('odooBranch');

        if ($staging === null || ! OdooStaging::isStagingName($staging->name) || $production?->odooBranch === null || $staging->odooBranch === null) {
            throw new RuntimeException('Staging sync only moves a staging branch.');
        }

        $payload = self::mergePayload($staging->odooBranch->git_branch, $production->odooBranch->git_branch);
        if (array_key_exists('force', $payload)) {
            throw new RuntimeException('Staging sync cannot rewrite history.');
        }

        $previous = $staging->odooBranch->git_branch;
        try {
            $profile = $staging->project->odooProfile;
            $app = $profile?->githubApp;
            if ($app === null || $profile->git_repository === null) {
                throw new RuntimeException('GitHub is not connected for this project.');
            }

            githubApi($app, '/repos/'.$profile->git_repository.'/merges', 'post', $payload);
            OdooAuditLog::write(auth()->id(), $staging->project_id, $staging->id, 'odoo.staging.sync', 'finished', [
                'previous_branch' => $previous,
                'head' => $payload['head'],
            ]);
        } catch (Throwable $e) {
            OdooAuditLog::write(auth()->id(), $staging->project_id, $staging->id, 'odoo.staging.sync', 'failed', [
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
