<?php

namespace App\Livewire;

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Actions\Server\UpdateCoolify;
use App\Enums\ProcessStatus;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\CoolifyUpgradeStatus;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

class Upgrade extends Component
{
    public bool $updateInProgress = false;

    public bool $isUpgradeAvailable = false;

    public string $latestVersion = '';

    public string $currentVersion = '';

    public bool $devMode = false;

    public bool $fullButton = false;

    public bool $showUpdateSteps = false;

    protected $listeners = ['updateAvailable' => 'checkUpdate'];

    public function mount()
    {
        $this->refreshUpgradeState();
    }

    public function checkUpdate()
    {
        try {
            $this->refreshUpgradeState();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    protected function refreshUpgradeState(): void
    {
        $this->currentVersion = config('constants.coolify.version');
        $this->latestVersion = get_latest_version_of_coolify();
        $this->devMode = isDev();

        if ($this->devMode) {
            $this->isUpgradeAvailable = true;

            return;
        }

        if (is_coolify_local_build()) {
            $this->applyLocalUpgradeState();

            return;
        }

        $settings = InstanceSettings::find(0);
        $hasNewerVersion = version_compare($this->latestVersion, $this->currentVersion, '>');
        $newVersionAvailable = (bool) data_get($settings, 'new_version_available', false);

        if ($settings && $newVersionAvailable && ! $hasNewerVersion) {
            $settings->update(['new_version_available' => false]);
            $newVersionAvailable = false;
        }

        $this->isUpgradeAvailable = $hasNewerVersion && $newVersionAvailable;
    }

    protected function applyLocalUpgradeState(): void
    {
        $settings = InstanceSettings::find(0);
        $revision = Cache::get('coolify:local-git');

        if (is_array($revision) && isset($revision['branch'], $revision['head'], $revision['upstream'])) {
            $this->currentVersion = $revision['branch'].'@'.substr((string) $revision['head'], 0, 12);
            $this->latestVersion = $revision['branch'].'@'.substr((string) $revision['upstream'], 0, 12);
            $this->isUpgradeAvailable = $revision['head'] !== $revision['upstream'];

            return;
        }

        $this->isUpgradeAvailable = (bool) data_get($settings, 'new_version_available', false);
    }

    public function toggleUpdateSteps(): void
    {
        $this->showUpdateSteps = ! $this->showUpdateSteps;
        $this->skipRender();
    }

    public function upgrade()
    {
        try {
            if (! isInstanceAdmin()) {
                abort(403);
            }
            if ($this->updateInProgress) {
                return;
            }
            $this->updateInProgress = true;
            dispatch(function () {
                try {
                    UpdateCoolify::run(manual_update: true);
                } catch (\Throwable $e) {
                    report($e);
                }
            })->afterResponse();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function getUpgradeStatus(): array
    {
        if (! $this->canReadUpgrade()) {
            return ['status' => 'none'];
        }

        $activity = $this->latestUpgradeActivity();
        $processStatus = (string) data_get($activity?->properties, 'status');
        $running = in_array($processStatus, [ProcessStatus::QUEUED->value, ProcessStatus::IN_PROGRESS->value], true);
        if ($running) {
            return $this->statusFromActivity($activity) ?? $this->upgradeVersions() + [
                'status' => 'in_progress',
                'step' => 0,
                'message' => 'Preparing update',
            ];
        }

        $fromFile = $this->statusFromFile();
        if ($fromFile['status'] !== 'none') {
            return $fromFile;
        }

        $inferred = $this->statusFromActivity($activity);
        if ($inferred !== null) {
            return $inferred;
        }

        return $fromFile;
    }

    /**
     * @return array{text: string}
     */
    public function upgradeLog(): array
    {
        if (! $this->canReadUpgrade()) {
            return ['text' => ''];
        }

        $activity = $this->latestUpgradeActivity();
        $text = RunRemoteProcess::decodeOutput($activity);
        $processStatus = (string) data_get($activity?->properties, 'status');
        $running = in_array($processStatus, [ProcessStatus::QUEUED->value, ProcessStatus::IN_PROGRESS->value], true);
        if (! $running) {
            $file = $this->upgradeFileLog();
            if (strlen($file) > strlen($text)) {
                $text = $file;
            }
        }

        if (strlen($text) > 20000) {
            $text = substr($text, -20000);
        }

        return ['text' => $text];
    }

    private function latestUpgradeActivity(): ?Activity
    {
        return Activity::query()
            ->where('created_at', '>=', now()->subHours(6))
            ->where(function ($query) {
                $query->where('properties->command', 'like', '%upgrade-local.sh%')
                    ->orWhere('properties->command', 'like', '%/upgrade.sh%');
            })
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{status: string, step?: int, message?: string, running_version: string, target_version: string}|null
     */
    private function statusFromActivity(?Activity $activity): ?array
    {
        $text = RunRemoteProcess::decodeOutput($activity);
        if ($text === '') {
            return null;
        }

        $step = 0;
        if (str_contains($text, 'Local upgrade completed')) {
            $step = 6;
        } elseif (str_contains($text, 'Waiting for health')) {
            $step = 5;
        } elseif (str_contains($text, 'Recreating the coolify') || str_contains($text, 'Starting container recreate')) {
            $step = 4;
        } elseif (str_contains($text, 'Building ')) {
            $step = 3;
        } elseif (str_contains($text, 'Checking out')) {
            $step = 2;
        } elseif (str_contains($text, 'Fetching origin') || str_contains($text, 'Fetching ')) {
            $step = 1;
        }

        $processStatus = (string) data_get($activity?->properties, 'status');
        $status = 'in_progress';
        if ($processStatus === ProcessStatus::ERROR->value || preg_match('/\] ERROR: /', $text) === 1) {
            $status = 'error';
        }

        $lines = preg_split("/\r\n|\n|\r/", trim($text)) ?: [];
        $message = trim((string) end($lines));
        if (strlen($message) > 180) {
            $message = substr($message, -180);
        }

        return $this->upgradeVersions() + [
            'status' => $status,
            'step' => $step,
            'message' => $message !== '' ? $message : 'Update in progress...',
        ];
    }

    /**
     * @return array{status: string, step?: int, message?: string, running_version: string, target_version: string}
     */
    private function statusFromFile(): array
    {
        $versions = $this->upgradeVersions();
        $server = Server::find(0);
        if (! $server) {
            return ['status' => 'none', ...$versions];
        }

        try {
            $content = instant_remote_process(
                ['cat /data/coolify/source/.upgrade-status 2>/dev/null || true'],
                $server,
                false,
                timeout: 8,
            );
            $content = trim($content ?? '');
        } catch (\Throwable) {
            return ['status' => 'none', ...$versions];
        }

        return CoolifyUpgradeStatus::fromFile(
            content: $content,
            runningVersion: $versions['running_version'],
            targetVersion: $versions['target_version'],
        );
    }

    private function upgradeFileLog(): string
    {
        $server = Server::find(0);
        if (! $server) {
            return '';
        }

        try {
            $text = instant_remote_process([
                'bash -c '.escapeshellarg('f=$(ls -1t /data/coolify/source/upgrade-2*.log 2>/dev/null | head -n 1); if [ -n "$f" ]; then tail -n 160 "$f"; fi'),
            ], $server, false, timeout: 8);
        } catch (\Throwable) {
            return '';
        }

        return (string) $text;
    }

    /**
     * @return array{running_version: string, target_version: string}
     */
    private function upgradeVersions(): array
    {
        return [
            'running_version' => $this->currentVersion !== '' ? $this->currentVersion : (string) config('constants.coolify.version'),
            'target_version' => $this->latestVersion !== '' ? $this->latestVersion : get_latest_version_of_coolify(),
        ];
    }

    public function canReadUpgrade(): bool
    {
        return isInstanceAdmin();
    }
}
