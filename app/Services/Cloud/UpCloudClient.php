<?php

namespace App\Services\Cloud;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class UpCloudClient extends AdditionalCloudServerClient
{
    public function __construct(private string $username, private string $password) {}

    public function ping(): bool
    {
        try {
            return $this->http()->get('/1.3/account')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function regions(): array
    {
        $response = $this->http()->get('/1.3/zone');
        $this->throwIfFailed($response, 'UpCloud zones');
        $zones = $response->json('zones.zone') ?? [];
        $regions = [];

        foreach ($zones as $zone) {
            $id = (string) ($zone['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $regions[] = [
                'id' => $id,
                'label' => (string) ($zone['description'] ?? $id),
            ];
        }

        return $regions;
    }

    public function plans(string $region): array
    {
        $response = $this->http()->get('/1.3/plan');
        $this->throwIfFailed($response, 'UpCloud plans');
        $plans = [];

        foreach ($response->json('plans.plan') ?? [] as $plan) {
            $id = (string) ($plan['name'] ?? '');

            if ($id === '') {
                continue;
            }

            $plans[] = [
                'id' => $id,
                'label' => trim(sprintf(
                    '%s · %s cores · %s MB',
                    $id,
                    $plan['core_number'] ?? '?',
                    $plan['memory_amount'] ?? '?'
                )),
            ];
        }

        return $plans;
    }

    public function images(string $region): array
    {
        $response = $this->http()->get('/1.3/storage/template');
        $this->throwIfFailed($response, 'UpCloud templates');
        $images = [];

        foreach ($response->json('storages.storage') ?? [] as $storage) {
            $id = (string) ($storage['uuid'] ?? '');
            $label = (string) ($storage['title'] ?? $id);

            if ($id === '' || $this->operatingSystemRank($label) === 9) {
                continue;
            }

            $images[] = [
                'id' => $id,
                'label' => $label,
            ];
        }

        return $this->preferOperatingSystems($images);
    }

    public function create(CloudServerRequest $request): CloudServerResult
    {
        $size = $this->storageSize($request->plan);
        $response = $this->http()->post('/1.3/server', [
            'server' => [
                'zone' => $request->region,
                'title' => $request->name,
                'hostname' => $request->name,
                'plan' => $request->plan,
                'storage_devices' => [
                    'storage_device' => [[
                        'action' => 'clone',
                        'storage' => $request->image,
                        'title' => $request->name.'-os',
                        'size' => $size,
                        'tier' => 'maxiops',
                    ]],
                ],
                'login_user' => [
                    'username' => 'root',
                    'ssh_keys' => [
                        'ssh_key' => [$request->publicKey],
                    ],
                ],
            ],
        ]);
        $this->throwIfFailed($response, 'UpCloud create');

        $server = $response->json('server') ?? [];
        $ip = null;

        foreach ($server['ip_addresses']['ip_address'] ?? [] as $address) {
            if (($address['access'] ?? null) === 'public' && ($address['family'] ?? null) === 'IPv4') {
                $ip = $address['address'] ?? null;
                break;
            }
        }

        return new CloudServerResult(
            id: (string) ($server['uuid'] ?? ''),
            status: isset($server['state']) ? (string) $server['state'] : null,
            ip: $this->usableIp(is_string($ip) ? $ip : null),
            user: 'root',
        );
    }

    public function delete(string $id, ?string $region = null): void
    {
        $response = $this->http()->delete('/1.3/server/'.$id, [
            'storages' => 1,
        ]);
        $this->throwIfFailed($response, 'UpCloud delete');
    }

    private function http(): PendingRequest
    {
        if ($this->username === '' || $this->password === '') {
            throw new RuntimeException('UpCloud username and password are required.');
        }

        return Http::withBasicAuth($this->username, $this->password)
            ->acceptJson()
            ->baseUrl('https://api.upcloud.com')
            ->timeout(20);
    }

    private function storageSize(string $plan): int
    {
        $response = $this->http()->get('/1.3/plan');
        $this->throwIfFailed($response, 'UpCloud plans');

        foreach ($response->json('plans.plan') ?? [] as $row) {
            if (($row['name'] ?? null) === $plan) {
                return max(10, (int) ($row['storage_size'] ?? 25));
            }
        }

        return 25;
    }
}
