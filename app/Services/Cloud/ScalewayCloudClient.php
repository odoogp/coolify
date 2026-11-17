<?php

namespace App\Services\Cloud;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ScalewayCloudClient extends AdditionalCloudServerClient
{
    public function __construct(private string $secretKey, private string $projectId) {}

    public function ping(): bool
    {
        try {
            return $this->http()->get('/instance/v1/zones')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function regions(): array
    {
        $response = $this->http()->get('/instance/v1/zones');
        $this->throwIfFailed($response, 'Scaleway zones');
        $regions = [];

        foreach ($response->json('zones') ?? [] as $zone) {
            $id = (string) $zone;

            if ($id === '') {
                continue;
            }

            $regions[] = [
                'id' => $id,
                'label' => $id,
            ];
        }

        return $regions;
    }

    public function plans(string $region): array
    {
        $response = $this->http()->get('/instance/v1/zones/'.$region.'/products/servers');
        $this->throwIfFailed($response, 'Scaleway plans');
        $plans = [];

        foreach ($response->json('servers') ?? [] as $id => $server) {
            $id = (string) $id;

            if ($id === '') {
                continue;
            }

            $ram = (int) ($server['ram'] ?? 0);
            $ramLabel = $ram > 1024 * 1024
                ? rtrim(rtrim(number_format($ram / 1024 / 1024 / 1024, 1, '.', ''), '0'), '.').' GB'
                : $ram.' MB';

            $plans[] = [
                'id' => $id,
                'label' => trim(sprintf('%s · %s · %s vCPU', $id, $ramLabel, $server['ncpus'] ?? '?')),
            ];
        }

        return $plans;
    }

    public function images(string $region): array
    {
        $response = $this->http()->get('/instance/v1/zones/'.$region.'/images', [
            'public' => 'true',
            'per_page' => 100,
        ]);
        $this->throwIfFailed($response, 'Scaleway images');
        $images = [];

        foreach ($response->json('images') ?? [] as $image) {
            $id = (string) ($image['id'] ?? '');
            $label = (string) ($image['name'] ?? $id);

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
        $keyName = 'gpsh-'.Str::lower(Str::substr(sha1($request->publicKey), 0, 12));
        $keyResponse = $this->http()->post('/iam/v1alpha1/ssh-keys', [
            'name' => $keyName,
            'public_key' => $request->publicKey,
            'project_id' => $this->projectId,
        ]);

        if (! $keyResponse->successful() && $keyResponse->status() !== 409) {
            $this->throwIfFailed($keyResponse, 'Scaleway SSH key');
        }

        $response = $this->http()->post('/instance/v1/zones/'.$request->region.'/servers', [
            'name' => $request->name,
            'commercial_type' => $request->plan,
            'image' => $request->image,
            'project' => $this->projectId,
            'dynamic_ip_required' => true,
            'enable_ipv6' => false,
        ]);
        $this->throwIfFailed($response, 'Scaleway create');

        $server = $response->json('server') ?? [];
        $id = (string) ($server['id'] ?? '');
        $state = (string) ($server['state'] ?? '');

        if ($id !== '' && $state !== 'running') {
            $this->http()->post('/instance/v1/zones/'.$request->region.'/servers/'.$id.'/action', [
                'action' => 'poweron',
            ]);
        }

        $ip = $server['public_ip']['address'] ?? null;

        return new CloudServerResult(
            id: $id,
            status: $state !== '' ? $state : null,
            ip: $this->usableIp(is_string($ip) ? $ip : null),
            user: $this->userForImage($request->imageLabel),
        );
    }

    public function delete(string $id, ?string $region = null): void
    {
        if ($region === null || $region === '') {
            throw new RuntimeException('Scaleway zone is required to delete an instance.');
        }

        $response = $this->http()->delete('/instance/v1/zones/'.$region.'/servers/'.$id);
        $this->throwIfFailed($response, 'Scaleway delete');
    }

    private function http(): PendingRequest
    {
        if ($this->secretKey === '' || $this->projectId === '') {
            throw new RuntimeException('Scaleway secret key and project ID are required.');
        }

        return Http::withHeaders([
            'X-Auth-Token' => $this->secretKey,
        ])->acceptJson()
            ->baseUrl('https://api.scaleway.com')
            ->timeout(20);
    }
}
