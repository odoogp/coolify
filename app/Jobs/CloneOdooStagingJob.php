<?php

namespace App\Jobs;

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Actions\Service\StartService;
use App\Enums\ProcessStatus;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\OdooEnvironmentBranch;
use App\Models\Project;
use App\Models\Service;
use App\Models\User;
use App\Support\GpshNotices;
use App\Support\OdooGit;
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

class CloneOdooStagingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 4200;

    public int $tries = 1;

    public function __construct(
        public int $projectId,
        public string $productionUuid,
        public string $branch,
        public string $cloneAddons,
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
            $project = Project::query()->with('odooProfile.githubApp', 'environments.odooBranch')->find($this->projectId);
            $production = $project?->environments->firstWhere('uuid', $this->productionUuid);
            if (! $project instanceof Project || ! $production instanceof Environment) {
                $this->progress(1, error: 'Clone starts from the production environment.');

                return;
            }

            $profile = $project->odooProfile;
            $repository = $profile?->git_repository;
            $app = $profile?->githubApp;
            $used = $this->usedBranches($project);

            $this->progress(1);
            $staging = $project->cloneProductionAsStaging();
            $this->progress(1, environment: $staging->uuid);
            if (filled($repository) && $app instanceof GithubApp) {
                OdooEnvironmentBranch::query()->updateOrCreate(
                    ['environment_id' => $staging->id],
                    ['git_branch' => $this->branch],
                );
            }

            $this->progress(2);
            $copied = $this->copyProductionService($production, $staging);

            $this->progress(3);
            if (filled($repository) && $app instanceof GithubApp) {
                $source = $this->cloneAddons === 'copy'
                    ? (string) ($production->odooBranch?->git_branch ?: $production->name)
                    : '';
                OdooGit::prepareStagingBranch($app, (string) $repository, $source, $this->branch, $used);
            }
            if ($copied instanceof Service && filled($repository) && $this->cloneAddons === 'copy') {
                OdooGit::cloneIntoService($copied);
            }

            $this->progress(4);
            $started = true;
            if ($copied instanceof Service && $copied->server?->isFunctional()) {
                OdooGit::whileServerIsFree($copied->server, function () use ($copied, $production): void {
                    OdooGit::useHttps($copied);
                    $activity = StartService::run($copied, pullLatestImages: false);
                    $this->waitForServiceStart($activity, $copied);
                    $original = $production->services()->get()->first(
                        fn (Service $service): bool => $service->supportsOdooJupyter()
                    );
                    if ($original instanceof Service) {
                        OdooGit::copyProductionData($original, $copied);
                    }
                });
                try {
                    OdooGit::waitUntilOpen($copied->fresh() ?? $copied);
                } catch (Throwable $openError) {
                    if (! OdooGit::loginAnswers($copied)) {
                        throw $openError;
                    }
                }
                GpshNotices::announce($copied, 'accessible');
                $copied->refresh();
                $copied->isConfigurationChanged(true);
            }

            $this->progress(5, done: true, redirect: $copied instanceof Service
                ? [
                    'name' => 'project.show',
                    'parameters' => [
                        'project_uuid' => $project->uuid,
                        'environment' => $staging->uuid,
                    ],
                ]
                : [
                    'name' => 'project.show',
                    'parameters' => ['project_uuid' => $project->uuid],
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

    private function copyProductionService(Environment $source, Environment $staging): ?Service
    {
        $original = $source->services()->get()->first(
            fn (Service $service): bool => $service->supportsOdooJupyter()
        );
        if (! $original instanceof Service) {
            return null;
        }

        $copy = $original->replicate();
        $copy->uuid = new_public_id();
        $copy->environment_id = $staging->id;
        $copy->config_hash = null;
        $copy->name = 'odoo-'.$staging->name;
        $copy->created_by = auth()->id() ?? $original->created_by;
        $copy->save();

        foreach ($original->environment_variables as $variable) {
            $cloned = $variable->replicate();
            $cloned->uuid = new_public_id();
            $cloned->resourceable_id = $copy->id;
            $cloned->resourceable_type = $copy->getMorphClass();
            $cloned->save();
        }

        OdooGit::assignCopiedBranch($copy->fresh());

        return $copy;
    }

    private function waitForServiceStart(mixed $activity, Service $service): void
    {
        if (! $activity instanceof Activity) {
            if (OdooGit::loginAnswers($service)) {
                return;
            }

            throw new RuntimeException('The staging service did not start.');
        }

        $mounted = false;
        // The copied database is not open yet. Accessible is announced after waitUntilOpen.
        $accessible = true;
        $deadline = time() + 3600;
        while (time() < $deadline) {
            $status = RunRemoteProcess::readStatus($activity);
            $output = RunRemoteProcess::logContains($activity, 'The service containers are running.')
                ? 'The service containers are running.'
                : '';
            GpshNotices::watch($service, $output, $status, $mounted, $accessible);
            if ($status === ProcessStatus::FINISHED->value) {
                return;
            }
            if (in_array($status, [ProcessStatus::ERROR->value, ProcessStatus::KILLED->value, ProcessStatus::CANCELLED->value], true)) {
                if (OdooGit::loginAnswers($service)) {
                    return;
                }

                $activity->refresh();

                throw new RuntimeException($this->startFailureMessage($activity));
            }
            sleep(3);
        }

        if (OdooGit::loginAnswers($service)) {
            return;
        }

        throw new RuntimeException('The staging service did not start.');
    }

    private function startFailureMessage(Activity $activity): string
    {
        $line = collect(preg_split('/\R/', RunRemoteProcess::decodeOutput($activity)) ?: [])
            ->map(fn ($line): string => trim((string) $line))
            ->filter(fn (string $line): bool => $line !== '')
            ->last();

        return is_string($line) && $line !== '' ? $line : 'The staging service did not start.';
    }

    /**
     * @return list<string>
     */
    private function usedBranches(Project $project): array
    {
        return $project->environments
            ->map(fn (Environment $environment): ?string => $environment->odooBranch?->git_branch)
            ->filter(fn (?string $branch): bool => filled($branch))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array{name: string, parameters: array<string, string>}|null  $redirect
     */
    private function progress(int $step, bool $done = false, ?string $error = null, ?array $redirect = null, ?string $environment = null): void
    {
        $current = Cache::get($this->cacheKey);
        $payload = [
            'step' => $step,
            'done' => $done,
            'error' => $error,
            'redirect' => $redirect,
            'environment' => $environment ?? (is_array($current) ? ($current['environment'] ?? null) : null),
        ];
        Cache::put($this->cacheKey, $payload, now()->addMinutes(30));
        Cache::put('odoo-clone-project-'.$this->projectId, $payload, now()->addMinutes(30));
    }
}
