<?php

namespace App\Livewire;

use App\Actions\Server\UpdateCoolify;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\CoolifyUpgradeStatus;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

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

        $server = Server::find(0);
        if (! $server) {
            return ['status' => 'none'];
        }

        $statusFile = '/data/coolify/source/.upgrade-status';

        try {
            $content = instant_remote_process(
                ["cat {$statusFile} 2>/dev/null || echo ''"],
                $server,
                false
            );
            $content = trim($content ?? '');
        } catch (\Throwable $e) {
            return ['status' => 'none'];
        }

        return CoolifyUpgradeStatus::fromFile(
            content: $content,
            runningVersion: $this->currentVersion !== '' ? $this->currentVersion : (string) config('constants.coolify.version'),
            targetVersion: $this->latestVersion !== '' ? $this->latestVersion : get_latest_version_of_coolify(),
        );
    }

    /**
     * @return array{text: string}
     */
    public function upgradeLog(): array
    {
        if (! $this->canReadUpgrade()) {
            return ['text' => ''];
        }

        $server = Server::find(0);
        if (! $server) {
            return ['text' => ''];
        }

        try {
            $text = instant_remote_process([
                'bash -c '.escapeshellarg('tail -n 120 "$(ls -1t /data/coolify/source/upgrade-*.log 2>/dev/null | head -n 1)" 2>/dev/null || true'),
            ], $server, false, timeout: 15);
        } catch (\Throwable) {
            return ['text' => ''];
        }

        $text = (string) $text;
        if (strlen($text) > 20000) {
            $text = substr($text, -20000);
        }

        return ['text' => $text];
    }

    public function canReadUpgrade(): bool
    {
        return isInstanceAdmin();
    }
}
