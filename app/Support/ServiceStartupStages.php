<?php

namespace App\Support;

/**
 * Friendly launch stages for the service startup dialog.
 * Derived from StartService echo lines and docker compose output.
 */
class ServiceStartupStages
{
    public const PREPARE = 'prepare';

    public const PULL = 'pull';

    public const NETWORK = 'network';

    public const START = 'start';

    public const HEALTH = 'health';

    public const ONLINE = 'online';

    /**
     * @return list<array{key: string, title: string, description: string, status: string}>
     */
    public static function fromOutput(string $output, ?int $exitCode = null, bool $serviceOnline = false, bool $failed = false): array
    {
        $failed = $failed || ($exitCode !== null && $exitCode !== 0);
        $seen = [
            self::PREPARE => self::containsAny($output, ['Saved configuration files']),
            self::PULL => self::containsAny($output, ['Pulling images.', 'Pulling images', ' Pulling', ' Pulled']),
            self::NETWORK => self::containsAny($output, ['Creating Docker network.', 'Creating Docker network']),
            self::START => self::containsAny($output, ['Starting service.', 'Starting service']),
            self::HEALTH => self::containsAny($output, [
                'Waiting until the service containers are running',
                'Waiting until HTTPS is applied',
                'The service containers are running',
                'Waiting',
            ]) || ($exitCode === 0 && ! $serviceOnline),
            self::ONLINE => self::containsAny($output, ['The service containers are running']) || $serviceOnline,
        ];

        // Once compose has started, earlier steps are done even if their echo was missed.
        if ($seen[self::START] || $seen[self::HEALTH] || $seen[self::ONLINE] || $exitCode !== null) {
            $seen[self::PREPARE] = true;
            if (! $seen[self::PULL]) {
                $seen[self::PULL] = true; // skipped / already present
            }
            if (! $seen[self::NETWORK]) {
                $seen[self::NETWORK] = true; // optional
            }
        }

        $current = self::PREPARE;
        foreach ([self::PREPARE, self::PULL, self::NETWORK, self::START, self::HEALTH, self::ONLINE] as $key) {
            if ($seen[$key]) {
                $current = $key;
            }
        }
        if ($serviceOnline) {
            $current = self::ONLINE;
        } elseif ($exitCode === 0 && ! $failed) {
            $current = self::HEALTH;
        } elseif ($exitCode === null && $seen[self::START] && ! $seen[self::HEALTH]) {
            $current = self::START;
        }

        $order = [self::PREPARE, self::PULL, self::NETWORK, self::START, self::HEALTH, self::ONLINE];
        $currentIndex = array_search($current, $order, true);
        if ($currentIndex === false) {
            $currentIndex = 0;
        }

        // English source strings — translate at render time with __().
        $definitions = [
            self::PREPARE => [
                'title' => 'Preparing configuration',
                'description' => 'Writing Compose and environment files.',
            ],
            self::PULL => [
                'title' => 'Pulling images',
                'description' => 'Downloading container images when needed.',
            ],
            self::NETWORK => [
                'title' => 'Creating network',
                'description' => 'Connecting the stack to Docker networks.',
            ],
            self::START => [
                'title' => 'Starting containers',
                'description' => 'Creating and starting each container.',
            ],
            self::HEALTH => [
                'title' => 'Checking health',
                'description' => 'Waiting until containers answer and look healthy.',
            ],
            self::ONLINE => [
                'title' => 'Service online',
                'description' => 'Ready to open in the browser.',
            ],
        ];

        $stages = [];
        foreach ($order as $index => $key) {
            if ($serviceOnline || ($current === self::ONLINE && ! $failed)) {
                $status = 'success';
            } elseif ($failed && $index < $currentIndex) {
                $status = 'success';
            } elseif ($failed && $index === $currentIndex) {
                $status = 'error';
            } elseif ($failed && $index > $currentIndex) {
                $status = 'pending';
            } elseif ($index < $currentIndex) {
                $status = 'success';
            } elseif ($index === $currentIndex) {
                $status = 'running';
            } else {
                $status = 'pending';
            }

            $stages[] = [
                'key' => $key,
                'title' => $definitions[$key]['title'],
                'description' => $definitions[$key]['description'],
                'status' => $status,
            ];
        }

        return $stages;
    }

    /**
     * @param  list<string>  $needles
     */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
