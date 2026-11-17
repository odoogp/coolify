<?php

namespace App\Services;

use App\Exceptions\RateLimitException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class HetznerService
{
    private string $token;

    private string $baseUrl = 'https://api.hetzner.cloud/v1';

    public function __construct(string $token)
    {
        $this->token = $token;
    }

    private function request(string $method, string $endpoint, array $data = [])
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])
            ->timeout(30)
            ->retry(3, function (int $attempt, \Exception $exception) {
                // Handle rate limiting (429 Too Many Requests)
                if ($exception instanceof RequestException) {
                    $response = $exception->response;

                    if ($response && $response->status() === 429) {
                        // Get rate limit reset timestamp from headers
                        $resetTime = $response->header('RateLimit-Reset');

                        if ($resetTime) {
                            // Calculate wait time until rate limit resets
                            $waitSeconds = max(0, $resetTime - time());

                            // Cap wait time at 60 seconds for safety
                            return min($waitSeconds, 60) * 1000;
                        }
                    }
                }

                // Exponential backoff for other retriable errors: 100ms, 200ms, 400ms
                return $attempt * 100;
            })
            ->{$method}($this->baseUrl.$endpoint, $data);

        if (! $response->successful()) {
            if ($response->status() === 429) {
                $retryAfter = $response->header('Retry-After');
                if ($retryAfter === null) {
                    $resetTime = $response->header('RateLimit-Reset');
                    $retryAfter = $resetTime ? max(0, (int) $resetTime - time()) : null;
                }

                throw new RateLimitException(
                    'Rate limit exceeded. Please try again later.',
                    $retryAfter !== null ? (int) $retryAfter : null
                );
            }

            throw new \Exception('Hetzner API error: '.$response->json('error.message', 'Unknown error'));
        }

        return $response->json();
    }

    private function requestPaginated(string $method, string $endpoint, string $resourceKey, array $data = []): array
    {
        $allResults = [];
        $page = 1;
        $seenPages = [];

        do {
            if (isset($seenPages[$page])) {
                break;
            }

            $seenPages[$page] = true;
            $data['page'] = $page;
            $data['per_page'] = 50;

            $response = $this->request($method, $endpoint, $data);

            if (isset($response[$resourceKey])) {
                $allResults = array_merge($allResults, $response[$resourceKey]);
            }

            $lastPage = (int) ($response['meta']['pagination']['last_page'] ?? $page);
            $nextPage = $response['meta']['pagination']['next_page'] ?? null;

            if ($nextPage === null && $page < $lastPage) {
                $nextPage = $page + 1;
            }

            $page = is_numeric($nextPage) ? (int) $nextPage : 0;
        } while ($page > 0 && $page <= 20);

        return $allResults;
    }

    public function getLocations(): array
    {
        return $this->requestPaginated('get', '/locations', 'locations');
    }

    public function getImages(): array
    {
        $images = [];

        foreach (['x86', 'arm'] as $architecture) {
            $images = array_merge($images, $this->requestPaginated('get', '/images', 'images', [
                'type' => 'system',
                'architecture' => $architecture,
                'status' => 'available',
            ]));
        }

        $images = $this->withMissingUbuntuImages($images);
        $unique = [];

        foreach ($images as $image) {
            $id = $image['id'] ?? null;

            if ($id === null || ! self::imageIsOrderable($image)) {
                continue;
            }

            $unique[$id] = $image;
        }

        return array_values($unique);
    }

    /**
     * Hetzner can omit current Ubuntu images from the first pages of /images.
     * Ask for each supported release by name when it is missing.
     *
     * @param  array<int, array<string, mixed>>  $images
     * @return array<int, array<string, mixed>>
     */
    private function withMissingUbuntuImages(array $images): array
    {
        $present = [];

        foreach ($images as $image) {
            $present[($image['name'] ?? '').'|'.($image['architecture'] ?? '')] = true;
        }

        foreach (['x86', 'arm'] as $architecture) {
            foreach (['ubuntu-22.04', 'ubuntu-24.04', 'ubuntu-26.04'] as $name) {
                if (isset($present[$name.'|'.$architecture])) {
                    continue;
                }

                $response = $this->request('get', '/images', [
                    'type' => 'system',
                    'name' => $name,
                    'architecture' => $architecture,
                    'per_page' => 5,
                ]);

                foreach ($response['images'] ?? [] as $image) {
                    if (($image['name'] ?? null) !== $name || ($image['architecture'] ?? null) !== $architecture) {
                        continue;
                    }

                    $images[] = $image;
                    $present[$name.'|'.$architecture] = true;
                }
            }
        }

        return $images;
    }

    /**
     * @param  array<string, mixed>  $image
     */
    public static function imageIsOrderable(array $image): bool
    {
        if (($image['type'] ?? null) !== 'system') {
            return false;
        }

        if (($image['status'] ?? 'available') !== 'available') {
            return false;
        }

        if (($image['deprecated'] ?? null) === true) {
            return false;
        }

        foreach ([
            $image['deprecated'] ?? null,
            data_get($image, 'deprecation.unavailable_after'),
        ] as $unavailableAt) {
            if (! is_string($unavailableAt) || $unavailableAt === '') {
                continue;
            }

            $timestamp = strtotime($unavailableAt);

            if ($timestamp !== false && $timestamp <= time()) {
                return false;
            }
        }

        return true;
    }

    public function getServerTypes(): array
    {
        $types = $this->requestPaginated('get', '/server_types', 'server_types');

        // Filter out entries where "deprecated" is explicitly true
        $filtered = array_filter($types, function ($type) {
            return ! (isset($type['deprecated']) && $type['deprecated'] === true);
        });

        return array_values($filtered);
    }

    public function getSshKeys(): array
    {
        return $this->requestPaginated('get', '/ssh_keys', 'ssh_keys');
    }

    public function getFirewalls(): array
    {
        return $this->requestPaginated('get', '/firewalls', 'firewalls');
    }

    public function getNetworks(): array
    {
        return $this->requestPaginated('get', '/networks', 'networks');
    }

    public function uploadSshKey(string $name, string $publicKey): array
    {
        $response = $this->request('post', '/ssh_keys', [
            'name' => $name,
            'public_key' => $publicKey,
        ]);

        return $response['ssh_key'] ?? [];
    }

    public function createServer(array $params): array
    {

        $response = $this->request('post', '/servers', $params);

        return $response['server'] ?? [];
    }

    public function enableServerBackup(int $serverId): array
    {
        $response = $this->request('post', "/servers/{$serverId}/actions/enable_backup");

        return $response['action'] ?? [];
    }

    public function getServer(int $serverId): array
    {
        $response = $this->request('get', "/servers/{$serverId}");

        return $response['server'] ?? [];
    }

    public function powerOnServer(int $serverId): array
    {
        $response = $this->request('post', "/servers/{$serverId}/actions/poweron");

        return $response['action'] ?? [];
    }

    public function deleteServer(int $serverId): void
    {
        $this->request('delete', "/servers/{$serverId}");
    }

    public function getServers(): array
    {
        return $this->requestPaginated('get', '/servers', 'servers');
    }

    public function findServerByIp(string $ip): ?array
    {
        $servers = $this->getServers();

        foreach ($servers as $server) {
            // Check IPv4
            $ipv4 = data_get($server, 'public_net.ipv4.ip');
            if ($ipv4 === $ip) {
                return $server;
            }

            // Check IPv6 (Hetzner returns the full /64 block)
            $ipv6 = data_get($server, 'public_net.ipv6.ip');
            if ($ipv6 && str_starts_with($ip, rtrim($ipv6, '/'))) {
                return $server;
            }
        }

        return null;
    }
}
