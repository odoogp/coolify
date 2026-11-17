<?php

namespace App\Services\Cloud;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleCloudClient extends AdditionalCloudServerClient
{
    private ?string $accessToken = null;

    /** @var array<string, mixed> */
    private array $serviceAccount;

    public function __construct(string $credentials)
    {
        $decoded = json_decode($credentials, true);
        $this->serviceAccount = is_array($decoded) ? $decoded : [];
    }

    public function ping(): bool
    {
        try {
            return $this->http()->get($this->projectUrl().'/zones')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function regions(): array
    {
        $response = $this->http()->get($this->projectUrl().'/zones');
        $this->throwIfFailed($response, 'Google Cloud zones');
        $regions = [];

        foreach ($response->json('items') ?? [] as $zone) {
            if (($zone['status'] ?? 'UP') !== 'UP') {
                continue;
            }

            $id = (string) ($zone['name'] ?? '');

            if ($id === '') {
                continue;
            }

            $regions[] = ['id' => $id, 'label' => $id];
        }

        usort($regions, fn (array $left, array $right): int => strcmp($left['id'], $right['id']));

        return $regions;
    }

    public function plans(string $region): array
    {
        $response = $this->http()->get($this->projectUrl().'/zones/'.$region.'/machineTypes');
        $this->throwIfFailed($response, 'Google Cloud machine types');
        $plans = [];

        foreach ($response->json('items') ?? [] as $type) {
            $id = (string) ($type['name'] ?? '');

            if ($id === '' || str_starts_with($id, 'custom-') || ! preg_match('/^(e2-|n2-standard-|t2d-)/', $id)) {
                continue;
            }

            $plans[] = [
                'id' => $id,
                'label' => trim(sprintf(
                    '%s · %s vCPU · %s MB',
                    $id,
                    $type['guestCpus'] ?? '?',
                    $type['memoryMb'] ?? '?'
                )),
            ];
        }

        return array_slice($plans, 0, 20);
    }

    public function images(string $region): array
    {
        $families = [
            ['project' => 'ubuntu-os-cloud', 'family' => 'ubuntu-2404-lts', 'label' => 'Ubuntu 24.04 LTS'],
            ['project' => 'ubuntu-os-cloud', 'family' => 'ubuntu-2204-lts', 'label' => 'Ubuntu 22.04 LTS'],
            ['project' => 'debian-cloud', 'family' => 'debian-12', 'label' => 'Debian 12'],
        ];
        $images = [];

        foreach ($families as $family) {
            $response = $this->http()->get(
                'https://compute.googleapis.com/compute/v1/projects/'.$family['project'].'/global/images/family/'.$family['family']
            );

            if (! $response->successful()) {
                continue;
            }

            $link = (string) ($response->json('selfLink') ?? '');

            if ($link === '') {
                continue;
            }

            $images[] = ['id' => $link, 'label' => $family['label']];
        }

        return $this->preferOperatingSystems($images);
    }

    public function create(CloudServerRequest $request): CloudServerResult
    {
        $name = $this->resourceName($request->name);
        $user = $this->userForImage($request->imageLabel);
        $response = $this->http()->post($this->projectUrl().'/zones/'.$request->region.'/instances', [
            'name' => $name,
            'machineType' => 'zones/'.$request->region.'/machineTypes/'.$request->plan,
            'disks' => [[
                'boot' => true,
                'autoDelete' => true,
                'initializeParams' => [
                    'sourceImage' => $request->image,
                ],
            ]],
            'networkInterfaces' => [[
                'network' => 'global/networks/default',
                'accessConfigs' => [[
                    'type' => 'ONE_TO_ONE_NAT',
                    'name' => 'External NAT',
                ]],
            ]],
            'metadata' => [
                'items' => [[
                    'key' => 'ssh-keys',
                    'value' => $user.':'.$request->publicKey,
                ]],
            ],
        ]);
        $this->throwIfFailed($response, 'Google Cloud create');

        $instance = $this->http()->get($this->projectUrl().'/zones/'.$request->region.'/instances/'.$name);
        $ip = null;

        if ($instance->successful()) {
            $ip = $instance->json('networkInterfaces.0.accessConfigs.0.natIP');
        }

        return new CloudServerResult(
            id: $name,
            status: $instance->successful() ? (string) ($instance->json('status') ?? 'PROVISIONING') : 'PROVISIONING',
            ip: $this->usableIp(is_string($ip) ? $ip : null),
            user: $user,
        );
    }

    public function delete(string $id, ?string $region = null): void
    {
        if ($region === null || $region === '') {
            throw new RuntimeException('Google Cloud zone is required to delete an instance.');
        }

        $response = $this->http()->delete($this->projectUrl().'/zones/'.$region.'/instances/'.$id);
        $this->throwIfFailed($response, 'Google Cloud delete');
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(25);
    }

    private function accessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        $email = (string) ($this->serviceAccount['client_email'] ?? '');
        $privateKey = (string) ($this->serviceAccount['private_key'] ?? '');
        $project = (string) ($this->serviceAccount['project_id'] ?? '');

        if ($email === '' || $privateKey === '' || $project === '') {
            throw new RuntimeException('Google Cloud needs a service account JSON key with project_id, client_email, and private_key.');
        }

        $now = time();
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode([
            'iss' => $email,
            'scope' => 'https://www.googleapis.com/auth/compute',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsigned = $header.'.'.$claims;
        $signature = '';

        if (! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Google Cloud service account key could not be signed.');
        }

        $response = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned.'.'.$this->base64Url($signature),
        ]);
        $this->throwIfFailed($response, 'Google Cloud authentication');
        $token = (string) $response->json('access_token');

        if ($token === '') {
            throw new RuntimeException('Google Cloud authentication failed.');
        }

        return $this->accessToken = $token;
    }

    private function projectUrl(): string
    {
        $project = (string) ($this->serviceAccount['project_id'] ?? '');

        if ($project === '') {
            throw new RuntimeException('Google Cloud service account JSON is missing project_id.');
        }

        return 'https://compute.googleapis.com/compute/v1/projects/'.$project;
    }

    private function resourceName(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9-]+/', '-', $name) ?? $name;
        $name = trim($name, '-');

        if ($name === '' || ! ctype_alpha($name[0])) {
            $name = 'g'.$name;
        }

        return substr($name, 0, 63);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
