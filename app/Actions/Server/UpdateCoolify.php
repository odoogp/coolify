<?php

namespace App\Actions\Server;

use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Lorisleiva\Actions\Concerns\AsAction;

class UpdateCoolify
{
    use AsAction;

    public ?Server $server = null;

    public ?string $latestVersion = null;

    public ?string $currentVersion = null;

    public function handle($manual_update = false)
    {
        if (isDev()) {
            Sleep::for(10)->seconds();

            return;
        }
        $settings = instanceSettings();
        $this->server = Server::find(0);
        if (! $this->server) {
            return;
        }

        if (is_coolify_local_build()) {
            $this->handleLocalBuild($settings, (bool) $manual_update);

            return;
        }

        // Fetch fresh version from CDN instead of using cache
        try {
            $response = Http::retry(3, 1000)->timeout(10)
                ->get(config('constants.coolify.versions_url'));

            if ($response->successful()) {
                $versions = $response->json();
                $this->latestVersion = data_get($versions, 'coolify.v4.version');
            } else {
                // Fallback to cache if CDN unavailable
                $cacheVersion = get_latest_version_of_coolify();

                // Validate cache version against current running version
                if ($cacheVersion && version_compare($cacheVersion, config('constants.coolify.version'), '<')) {
                    Log::error('Failed to fetch fresh version from CDN and cache is corrupted/outdated', [
                        'cached_version' => $cacheVersion,
                        'current_version' => config('constants.coolify.version'),
                    ]);
                    throw new \Exception(
                        'Cannot determine latest version: CDN unavailable and cache version '.
                        "({$cacheVersion}) is older than running version (".config('constants.coolify.version').')'
                    );
                }

                $this->latestVersion = $cacheVersion;
                Log::warning('Failed to fetch fresh version from CDN (unsuccessful response), using validated cache', [
                    'version' => $cacheVersion,
                ]);
            }
        } catch (\Throwable $e) {
            $cacheVersion = get_latest_version_of_coolify();

            // Validate cache version against current running version
            if ($cacheVersion && version_compare($cacheVersion, config('constants.coolify.version'), '<')) {
                Log::error('Failed to fetch fresh version from CDN and cache is corrupted/outdated', [
                    'error' => $e->getMessage(),
                    'cached_version' => $cacheVersion,
                    'current_version' => config('constants.coolify.version'),
                ]);
                throw new \Exception(
                    'Cannot determine latest version: CDN unavailable and cache version '.
                    "({$cacheVersion}) is older than running version (".config('constants.coolify.version').')'
                );
            }

            $this->latestVersion = $cacheVersion;
            Log::warning('Failed to fetch fresh version from CDN, using validated cache', [
                'error' => $e->getMessage(),
                'version' => $cacheVersion,
            ]);
        }

        $this->currentVersion = config('constants.coolify.version');
        if (! $manual_update) {
            if (! $settings->is_auto_update_enabled) {
                return;
            }
            if ($this->latestVersion === $this->currentVersion) {
                return;
            }
            if (version_compare($this->latestVersion, $this->currentVersion, '<')) {
                return;
            }
        }

        // ALWAYS check for downgrades (even for manual updates)
        if (version_compare($this->latestVersion, $this->currentVersion, '<')) {
            Log::error('Downgrade prevented', [
                'target_version' => $this->latestVersion,
                'current_version' => $this->currentVersion,
                'manual_update' => $manual_update,
            ]);
            throw new \Exception(
                "Cannot downgrade from {$this->currentVersion} to {$this->latestVersion}. ".
                'If you need to downgrade, please do so manually via Docker commands.'
            );
        }

        $this->update();
        $settings->new_version_available = false;
        $settings->save();
    }

    /**
     * @return array{branch: string, head: string, upstream: string}|null
     */
    public static function parseLocalGitRevision(string $output): ?array
    {
        $parts = explode("\t", trim($output));
        if (count($parts) !== 3) {
            return null;
        }

        [$branch, $head, $upstream] = $parts;
        if ($branch === '' || ! preg_match('/\A[0-9a-f]{40}\z/', $head) || ! preg_match('/\A[0-9a-f]{40}\z/', $upstream)) {
            return null;
        }

        return [
            'branch' => $branch,
            'head' => $head,
            'upstream' => $upstream,
        ];
    }

    public function syncLocalUpdateAvailability(): void
    {
        $settings = instanceSettings();
        $this->server = Server::find(0);
        if (! $this->server instanceof Server) {
            return;
        }

        $revision = $this->localGitRevision();
        if ($revision === null) {
            return;
        }

        Cache::put('coolify:local-git', $revision, 3600);
        $settings->update([
            'new_version_available' => $revision['head'] !== $revision['upstream'],
        ]);
    }

    private function handleLocalBuild(InstanceSettings $settings, bool $manualUpdate): void
    {
        if (! $manualUpdate && ! $settings->is_auto_update_enabled) {
            return;
        }

        if (! $manualUpdate) {
            $revision = $this->localGitRevision();
            if ($revision === null || $revision['head'] === $revision['upstream']) {
                return;
            }
        }

        $this->update();
        $settings->new_version_available = false;
        $settings->save();
    }

    /**
     * @return array{branch: string, head: string, upstream: string}|null
     */
    private function localGitRevision(): ?array
    {
        if (! $this->server instanceof Server) {
            return null;
        }

        try {
            $output = instant_remote_process(
                [
                    ...$this->localUpgradeScriptInstallCommands(),
                    'bash /data/coolify/source/upgrade-local.sh --status',
                ],
                $this->server,
                true,
                false,
                120,
            );
        } catch (\Throwable $e) {
            Log::warning('Local Coolify update check failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $revision = self::parseLocalGitRevision((string) $output);
        if ($revision !== null) {
            Cache::put('coolify:local-git', $revision, 3600);
        }

        return $revision;
    }

    /**
     * @return list<string>
     */
    private function localUpgradeScriptInstallCommands(): array
    {
        $encoded = base64_encode((string) file_get_contents(base_path('scripts/upgrade-local.sh')));

        return [
            "base64 -d > /data/coolify/source/upgrade-local.sh <<'COOLIFY_LOCAL_UPGRADE'",
            $encoded,
            'COOLIFY_LOCAL_UPGRADE',
            'chmod +x /data/coolify/source/upgrade-local.sh',
        ];
    }

    private function update()
    {
        if (is_coolify_local_build()) {
            remote_process([
                ...$this->localUpgradeScriptInstallCommands(),
                'bash /data/coolify/source/upgrade-local.sh',
            ], $this->server);

            return;
        }

        $latestHelperImageVersion = getHelperVersion();
        $upgradeScriptUrl = config('constants.coolify.upgrade_script_url');
        $registryUrl = coolifyRegistryUrl();

        remote_process([
            "curl -fsSL {$upgradeScriptUrl} -o /data/coolify/source/upgrade.sh",
            'bash /data/coolify/source/upgrade.sh '.
                escapeshellarg($this->latestVersion).' '.
                escapeshellarg($latestHelperImageVersion).' '.
                escapeshellarg($registryUrl),
        ], $this->server);
    }
}
