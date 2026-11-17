<?php

namespace App\Support;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;

class OdooDeployPipeline
{
    public const STAGES = ['git', 'sync', 'restart', 'running'];

    /**
     * Map a modules-update deployment to the Railway-style status line for Odoo.
     *
     * @return array{
     *     stages: list<array{key: string, label: string, state: string}>,
     *     current: string,
     *     status: string,
     *     failed: bool,
     *     active: bool,
     *     deployment: ?ApplicationDeploymentQueue
     * }
     */
    public static function fromDeployment(?ApplicationDeploymentQueue $deployment): array
    {
        if (! $deployment instanceof ApplicationDeploymentQueue) {
            return [
                'stages' => self::idleStages(),
                'current' => 'git',
                'status' => 'idle',
                'failed' => false,
                'active' => false,
                'deployment' => null,
            ];
        }

        $status = (string) $deployment->status;
        $logs = (string) ($deployment->logs ?? '');
        $failed = in_array($status, [
            ApplicationDeploymentStatus::FAILED->value,
            ApplicationDeploymentStatus::CANCELLED_BY_USER->value,
        ], true);
        $finished = $status === ApplicationDeploymentStatus::FINISHED->value;
        $queued = $status === ApplicationDeploymentStatus::QUEUED->value;
        $inProgress = $status === ApplicationDeploymentStatus::IN_PROGRESS->value;

        $cloneDone = str_contains($logs, 'Clone finished')
            || str_contains($logs, 'Modules are on disk')
            || str_contains($logs, 'Module update finished');
        $restarting = str_contains($logs, 'Restarting Odoo')
            || str_contains($logs, 'Odoo restart finished');

        if ($failed) {
            $current = match (true) {
                $restarting => 'restart',
                $cloneDone => 'restart',
                default => 'sync',
            };
            if ($queued) {
                $current = 'git';
            }
        } elseif ($finished) {
            $current = 'running';
        } elseif ($queued) {
            $current = 'git';
        } elseif ($inProgress && $restarting) {
            $current = 'restart';
        } elseif ($inProgress && $cloneDone) {
            $current = 'restart';
        } elseif ($inProgress) {
            $current = 'sync';
        } else {
            $current = 'git';
        }

        return [
            'stages' => self::buildStages($current, $failed, $finished),
            'current' => $current,
            'status' => $status,
            'failed' => $failed,
            'active' => $queued || $inProgress,
            'deployment' => $deployment,
        ];
    }

    /**
     * @return list<array{key: string, label: string, state: string}>
     */
    private static function idleStages(): array
    {
        return collect(self::STAGES)
            ->map(fn (string $key): array => [
                'key' => $key,
                'label' => self::label($key),
                'state' => 'idle',
            ])
            ->all();
    }

    /**
     * @return list<array{key: string, label: string, state: string}>
     */
    private static function buildStages(string $current, bool $failed, bool $finished): array
    {
        $order = array_values(self::STAGES);
        $currentIndex = array_search($current, $order, true);
        if ($currentIndex === false) {
            $currentIndex = 0;
        }

        return collect($order)->map(function (string $key, int $index) use ($currentIndex, $failed, $finished): array {
            $state = 'idle';
            if ($finished && $key === 'running') {
                $state = 'done';
            } elseif ($finished && $index < count(self::STAGES) - 1) {
                $state = 'done';
            } elseif ($failed && $index === $currentIndex) {
                $state = 'failed';
            } elseif ($failed && $index < $currentIndex) {
                $state = 'done';
            } elseif ($index < $currentIndex) {
                $state = 'done';
            } elseif ($index === $currentIndex) {
                $state = $finished ? 'done' : 'current';
            }

            return [
                'key' => $key,
                'label' => self::label($key),
                'state' => $state,
            ];
        })->all();
    }

    private static function label(string $key): string
    {
        return match ($key) {
            'git' => __('Git'),
            'sync' => __('Sync'),
            'restart' => __('Restart'),
            'running' => __('Running'),
            default => $key,
        };
    }
}
