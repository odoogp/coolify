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
 * Create a GitHub repository for a project that started without GitHub and push
 * existing custom addons. Must not run inside a Livewire request (timeout / 419).
 */
class AssociateOdooGithubRepositoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public int $serviceId,
        public string $cacheKey,
        public int $userId,
        public int $githubAppId,
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
            $project = $service?->environment?->project;
            $app = GithubApp::query()->find($this->githubAppId);
            if (! $service instanceof Service || $project === null || ! $app instanceof GithubApp) {
                throw new RuntimeException('The GitHub repository could not be created.');
            }

            $this->progress(1);
            OdooGit::associateNewRepository($service, $app);

            $this->progress(2, done: true);
        } catch (Throwable $exception) {
            $status = Cache::get($this->cacheKey);
            $this->progress(is_array($status) ? (int) ($status['step'] ?? 1) : 1, error: $exception->getMessage());
        } finally {
            if (! $alreadyAuthenticated) {
                Auth::logout();
            }
        }
    }

    private function progress(int $step, bool $done = false, ?string $error = null): void
    {
        Cache::put($this->cacheKey, [
            'step' => $step,
            'done' => $done,
            'error' => $error,
        ], now()->addMinutes(30));
    }
}
