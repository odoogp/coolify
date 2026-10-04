<?php

namespace App\Jobs;

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Actions\Service\StartService;
use App\Enums\ProcessStatus;
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

class LaunchOdooProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public int $serviceId,
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
            if ($user instanceof User) {
                Auth::setUser($user);
            }
        }

        try {
            $service = Service::query()->find($this->serviceId);
            $project = $service?->environment?->project;
            if (! $service instanceof Service || $project === null) {
                throw new RuntimeException('The project could not be started.');
            }

            $this->progress(1);
            if (! $service->server?->isFunctional()) {
                throw new RuntimeException('No server is available for this Odoo service.');
            }

            $this->progress(2);
            OdooGit::whileServerIsFree($service->server, function () use ($service): void {
                $current = $service->fresh() ?? $service;
                OdooGit::useHttps($current);
                $activity = StartService::run($current, pullLatestImages: false);
                $this->waitForServiceStart($activity, $service);
            });

            $environment = $service->environment;
            $this->progress(4, done: true, redirect: [
                'name' => 'project.show',
                'parameters' => [
                    'project_uuid' => $project->uuid,
                    'environment' => $environment->uuid,
                ],
            ]);
        } catch (Throwable $exception) {
            $status = Cache::get($this->cacheKey);
            $this->progress(is_array($status) ? (int) ($status['step'] ?? 1) : 1, error: $exception->getMessage());
        } finally {
            if (! $alreadyAuthenticated) {
                Auth::logout();
            }
        }
    }

    private function waitForServiceStart(mixed $activity, Service $service): void
    {
        if (! $activity instanceof Activity) {
            throw new RuntimeException('The project could not be started.');
        }

        $mounted = false;
        $accessible = false;
        $deadline = time() + 1200;
        while (time() < $deadline) {
            $status = RunRemoteProcess::readStatus($activity);
            if (RunRemoteProcess::logContains($activity, 'HTTPS')) {
                $this->progress(3);
            }
            $output = RunRemoteProcess::logContains($activity, 'The service containers are running.')
                ? 'The service containers are running.'
                : '';
            GpshNotices::watch($service, $output, $status, $mounted, $accessible);
            if ($status === ProcessStatus::FINISHED->value) {
                return;
            }
            if (in_array($status, [ProcessStatus::ERROR->value, ProcessStatus::KILLED->value, ProcessStatus::CANCELLED->value], true)) {
                $activity->refresh();
                $line = collect(preg_split('/\R/', RunRemoteProcess::decodeOutput($activity)) ?: [])
                    ->map(fn ($line): string => trim((string) $line))
                    ->filter(fn (string $line): bool => $line !== '')
                    ->last();

                throw new RuntimeException(is_string($line) && $line !== '' ? $line : 'The project could not be started.');
            }
            sleep(3);
        }

        throw new RuntimeException('The project could not be started.');
    }

    /**
     * @param  array{name: string, parameters: array<string, string>}|null  $redirect
     */
    private function progress(int $step, bool $done = false, ?string $error = null, ?array $redirect = null): void
    {
        Cache::put($this->cacheKey, [
            'step' => $step,
            'done' => $done,
            'error' => $error,
            'redirect' => $redirect,
        ], now()->addMinutes(30));
    }
}
