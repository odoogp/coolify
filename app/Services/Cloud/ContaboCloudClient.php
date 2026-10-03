<?php

namespace App\Services\Cloud;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ContaboCloudClient extends AdditionalCloudServerClient
{
    private ?string $accessToken = null;

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $username,
        private string $password,
    ) {}

    public function ping(): bool
    {
        try {
            return $this->http()->get('/v1/data-centers')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function regions(): array
    {
        $response = $this->http()->get('/v1/data-centers');
        $this->throwIfFailed($response, 'Contabo regions');
        $regions = [];

        foreach ($response->json('data') ?? [] as $region) {
            $id = (string) ($region['slug'] ?? $region['name'] ?? '');

            if ($id === '') {
                continue;
            }

            $regions[] = [
                'id' => $id,
                'label' => (string) ($region['name'] ?? $id),
            ];
        }

        return $regions;
    }

    public function plans(string $region): array
    {
        $response = $this->http()->get('/v1/products');

        if ($response->successful()) {
            $plans = [];

            foreach ($response->json('data') ?? [] as $product) {
                $id = (string) ($product['productId'] ?? $product['id'] ?? '');

                if ($id === '') {
                    continue;
                }

                $plans[] = [
                    'id' => $id,
                    'label' => (string) ($product['name'] ?? $id),
                ];
            }

            if ($plans !== []) {
                return $plans;
            }
        }

        return [
            ['id' => 'V91', 'label' => 'Cloud VPS S'],
            ['id' => 'V92', 'label' => 'Cloud VPS M'],
            ['id' => 'V93', 'label' => 'Cloud VPS L'],
            ['id' => 'V94', 'label' => 'Cloud VPS XL'],
            ['id' => 'V95', 'label' => 'Cloud VPS XXL'],
        ];
    }

    public function images(string $region): array
    {
        $response = $this->http()->get('/v1/compute/images', [
            'size' => 100,
        ]);
        $this->throwIfFailed($response, 'Contabo images');
        $images = [];

        foreach ($response->json('data') ?? [] as $image) {
            $id = (string) ($image['imageId'] ?? $image['id'] ?? '');
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
        $secretResponse = $this->http()->post('/v1/secrets', [
            'name' => 'gpsh-'.Str::lower(Str::substr(sha1($request->publicKey), 0, 12)),
            'type' => 'ssh',
            'value' => $request->publicKey,
        ]);
        $this->throwIfFailed($secretResponse, 'Contabo SSH key');

        $secretId = $secretResponse->json('data.0.secretId')
            ?? $secretResponse->json('data.secretId');

        $response = $this->http()->post('/v1/compute/instances', [
            'imageId' => $request->image,
            'productId' => $request->plan,
            'region' => $request->region,
            'period' => 1,
            'displayName' => $request->name,
            'defaultUser' => 'root',
            'sshKeys' => $secretId ? [(int) $secretId] : [],
        ]);
        $this->throwIfFailed($response, 'Contabo create');

        $instance = $response->json('data.0') ?? $response->json('data') ?? [];
        $ip = $instance['ipConfig']['v4']['ip'] ?? $instance['ip'] ?? null;

        return new CloudServerResult(
            id: (string) ($instance['instanceId'] ?? $instance['id'] ?? ''),
            status: isset($instance['status']) ? (string) $instance['status'] : null,
            ip: $this->usableIp(is_string($ip) ? $ip : null),
            user: 'root',
        );
    }

    public function delete(string $id, ?string $region = null): void
    {
        $response = $this->http()->delete('/v1/compute/instances/'.$id);
        $this->throwIfFailed($response, 'Contabo delete');
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->acceptJson()
            ->withHeaders([
                'x-request-id' => (string) Str::uuid(),
            ])
            ->baseUrl('https://api.contabo.com')
            ->timeout(20);
    }

    private function accessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        if ($this->clientId === '' || $this->clientSecret === '' || $this->username === '' || $this->password === '') {
            throw new RuntimeException('Contabo client ID, client secret, username, and password are required.');
        }

        $response = Http::asForm()
            ->timeout(20)
            ->post('https://auth.contabo.com/auth/realms/contabo/protocol/openid-connect/token', [
                'grant_type' => 'password',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'username' => $this->username,
                'password' => $this->password,
            ]);
        $this->throwIfFailed($response, 'Contabo authentication');

        $token = (string) $response->json('access_token');

        if ($token === '') {
            throw new RuntimeException('Contabo authentication failed.');
        }

        return $this->accessToken = $token;
    }
}
