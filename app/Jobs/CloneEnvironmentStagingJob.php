<?php

namespace App\Jobs;

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Actions\Service\StartService;
use App\Enums\ProcessStatus;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Throwable;

/**
 * Clone every service from production into a new staging environment.
 * Odoo/GitHub/neutralize stays on {@see CloneOdooStagingJob}.
 */
class CloneEnvironmentStagingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 4200;

    public int $tries = 1;

    public function __construct(
        public int $projectId,
        public string $productionUuid,
        public string $cacheKey,
        public int $userId,
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $currentId = auth()->id();
        $alreadyAuthenticated = $currentId !== null && (int) $currentId === $this->userId;
        if (! $alreadyAuthenticated) {
            $user = User::query()->whereKey($this->userId)->first();
            if (! $user instanceof User) {
                $this->progress(1, error: 'Clone starts from the production environment.');

                return;
            }
            Auth::setUser($user);
        }

        $staging = null;
        $started = false;

        try {
            $project = Project::query()->with('environments.services')->find($this->projectId);
            $production = $project?->environments->firstWhere('uuid', $this->productionUuid);
            if (! $project instanceof Project || ! $production instanceof Environment || ! $production->isProduction()) {
                $this->progress(1, error: 'Clone starts from the production environment.');

                return;
            }

            $this->progress(1);
            $staging = $project->cloneProductionAsStaging();
            $this->progress(1, environment: $staging->uuid);

            $this->progress(2);
            $copies = $this->copyProductionServices($production, $staging);
            if ($copies === []) {
                throw new RuntimeException('Production has no services to clone.');
            }

            $this->progress(3);
            $started = true;
            foreach ($copies as $copy) {
                if ($copy->server?->isFunctional()) {
                    $activity = StartService::run($copy, pullLatestImages: false);
                    $this->waitForServiceStart($activity);
                }
            }

            $this->progress(4, done: true, redirect: [
                'name' => 'project.show',
                'parameters' => [
                    'project_uuid' => $project->uuid,
                    'environment' => $staging->uuid,
                ],
            ]);
        } catch (Throwable $exception) {
            if ($staging instanceof Environment && ! $started) {
                try {
                    $staging->services()->each(function (Service $service): void {
                        $service->environment_variables()->delete();
                        $service->delete();
                    });
                    $staging->unsetRelation('services');
                    if (! $staging->services()->exists() && ! $staging->applications()->exists()) {
                        $staging->delete();
                    }
                } catch (Throwable) {
                    // Keep the clone error on the screen if cleanup fails.
                }
            }
            $status = Cache::get($this->cacheKey);
            $this->progress(is_array($status) ? (int) ($status['step'] ?? 1) : 1, error: $exception->getMessage());
        } finally {
            if (! $alreadyAuthenticated) {
                Auth::logout();
            }
        }
    }

    /**
     * @return list<Service>
     */
    private function copyProductionServices(Environment $source, Environment $staging): array
    {
        $copies = [];
        foreach ($source->services as $original) {
            $original->loadMissing('environment_variables');
            $copy = $original->replicate();
            $copy->uuid = new_public_id();
            $copy->environment_id = $staging->id;
            $copy->config_hash = null;
            $copy->name = $original->name.'-'.$staging->name;
            $copy->created_by = auth()->id() ?? $original->created_by;
            $copy->save();

            foreach ($original->environment_variables as $variable) {
                $cloned = $variable->replicate();
                $cloned->uuid = new_public_id();
                $cloned->resourceable_id = $copy->id;
                $cloned->resourceable_type = $copy->getMorphClass();
                $cloned->save();
            }

            $copies[] = $copy->fresh() ?? $copy;
        }

        return $copies;
    }

    private function waitForServiceStart(mixed $activity): void
    {
        if (! $activity instanceof Activity) {
            return;
        }

        $deadline = time() + 3600;
        while (time() < $deadline) {
            $status = RunRemoteProcess::readStatus($activity);
            if ($status === ProcessStatus::FINISHED->value) {
                return;
            }
            if (in_array($status, [ProcessStatus::ERROR->value, ProcessStatus::KILLED->value, ProcessStatus::CANCELLED->value], true)) {
                throw new RuntimeException('The staging service did not start.');
            }
            sleep(3);
        }

        throw new RuntimeException('The staging service did not start.');
    }

    private function progress(int $step, bool $done = false, ?string $error = null, ?array $redirect = null, ?string $environment = null): void
    {
        Cache::put($this->cacheKey, [
            'step' => $step,
            'done' => $done,
            'error' => $error,
            'redirect' => $redirect,
            'url' => null,
            'environment' => $environment,
        ], now()->addMinutes(30));
    }
}
