<?php

namespace App\Jobs;

use App\Models\GithubApp;
use App\Models\Service;
use App\Models\User;
use App\Support\OdooGit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Link a GitHub repository to an Odoo service (create new, or attach existing + branch).
 * Must not run inside a Livewire request (GitHub waits / CSRF → "Page expired").
 */
class AssociateOdooGithubRepositoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    /**
     * @param  list<string>  $existingBranches  Branch tips already loaded in the UI when possible
     */
    public function __construct(
        public int $serviceId,
        public string $cacheKey,
        public int $userId,
        public int $githubAppId,
        public ?string $existingFullName = null,
        public ?int $existingRepositoryId = null,
        public ?string $existingBranch = null,
        public array $existingBranches = [],
        public bool $startLaunch = false,
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $currentId = auth()->id();
        $alreadyAuthenticated = $currentId !== null && (int) $currentId === $this->userId;
        if (! $alreadyAuthenticated) {
            $user = User::query()->whereKey($this->userId)->first();
            if ($user instanceof User) {
                Auth::setUser($user);
            }
        }

        try {
            $service = Service::query()->find($this->serviceId);
            $environment = $service?->environment;
            $project = $environment?->project;
            $app = GithubApp::query()->find($this->githubAppId);
            if (! $service instanceof Service || $project === null || $environment === null || ! $app instanceof GithubApp) {
                throw new RuntimeException('The GitHub repository could not be associated.');
            }

            $this->progress(1);

            if (blank($this->existingFullName) || blank($this->existingBranch) || $this->existingRepositoryId === null) {
                OdooGit::associateNewRepository($service, $app);
                $this->progress(2, done: true);

                return;
            }

            $branches = $this->existingBranches;
            if ($branches === []) {
                [$owner, $name] = array_pad(explode('/', (string) $this->existingFullName, 2), 2, '');
                $branches = OdooGit::branchNames($app, $owner, $name);
            }

            OdooGit::attachExisting(
                $project,
                $app,
                (string) $this->existingFullName,
                (int) $this->existingRepositoryId,
                $branches,
                $environment,
                (string) $this->existingBranch,
            );

            $environment->unsetRelation('odooBranch');
            $environment->load('odooBranch');
            $row = $environment->odooBranch;
            if ($row !== null) {
                $row->update([
                    'status' => 'updating',
                    'service_id' => $row->service_id ?: $service->id,
                ]);
                SyncOdooAddonsJob::dispatch(odooEnvironmentBranchId: $row->id);
            }

            if ($this->startLaunch) {
                $launchKey = 'launch-odoo-'.$service->uuid;
                Cache::put($launchKey, ['step' => 1, 'done' => false, 'error' => null, 'redirect' => null], now()->addMinutes(30));
                LaunchOdooProjectJob::dispatch($service->id, $launchKey, $this->userId);
                $this->progress(2, done: true, redirect: [
                    'name' => 'project.show',
                    'parameters' => ['project_uuid' => $project->uuid],
                ]);

                return;
            }

            $this->progress(2, done: true, message: __('Repository :repository linked. Cloning branch :branch…', [
                'repository' => $this->existingFullName,
                'branch' => $this->existingBranch,
            ]));
        } catch (Throwable $exception) {
            $status = Cache::get($this->cacheKey);
            $this->progress(is_array($status) ? (int) ($status['step'] ?? 1) : 1, error: $exception->getMessage());
        } finally {
            if (! $alreadyAuthenticated) {
                Auth::logout();
            }
        }
    }

    /**
     * @param  array{name: string, parameters: array<string, mixed>}|null  $redirect
     */
    private function progress(int $step, bool $done = false, ?string $error = null, ?array $redirect = null, ?string $message = null): void
    {
        Cache::put($this->cacheKey, [
            'step' => $step,
            'done' => $done,
            'error' => $error,
            'redirect' => $redirect,
            'message' => $message,
        ], now()->addMinutes(30));
    }
}
