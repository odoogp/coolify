<?php

namespace App\Services\Cloud;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ExoscaleCloudClient extends AdditionalCloudServerClient
{
    public function __construct(private string $apiKey, private string $apiSecret) {}

    public function ping(): bool
    {
        try {
            return $this->request('GET', 'ch-gva-2', '/v2/zone')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function regions(): array
    {
        $response = $this->request('GET', 'ch-gva-2', '/v2/zone');
        $this->throwIfFailed($response, 'Exoscale zones');
        $regions = [];

        foreach ($response->json('zones') ?? [] as $zone) {
            $id = (string) ($zone['name'] ?? $zone['id'] ?? '');

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
        $response = $this->request('GET', $region, '/v2/instance-type');
        $this->throwIfFailed($response, 'Exoscale plans');
        $plans = [];

        foreach ($response->json('instance-types') ?? [] as $type) {
            $id = (string) ($type['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $plans[] = [
                'id' => $id,
                'label' => trim(sprintf(
                    '%s · %s vCPU · %s MB',
                    $type['size'] ?? $id,
                    $type['cpus'] ?? '?',
                    $type['memory'] ?? '?'
                )),
            ];
        }

        return $plans;
    }

    public function images(string $region): array
    {
        $response = $this->request('GET', $region, '/v2/template');
        $this->throwIfFailed($response, 'Exoscale templates');
        $images = [];

        foreach ($response->json('templates') ?? [] as $template) {
            $id = (string) ($template['id'] ?? '');
            $label = (string) ($template['name'] ?? $id);

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
        $keyResponse = $this->request('POST', $request->region, '/v2/ssh-key', [
            'name' => $keyName,
            'public-key' => $request->publicKey,
        ]);

        if (! $keyResponse->successful() && $keyResponse->status() !== 409) {
            $this->throwIfFailed($keyResponse, 'Exoscale SSH key');
        }

        $response = $this->request('POST', $request->region, '/v2/instance', [
            'name' => $request->name,
            'disk-size' => 10,
            'instance-type' => ['id' => $request->plan],
            'template' => ['id' => $request->image],
            'ssh-key' => ['name' => $keyName],
            'public-ip-assignment' => 'inet4',
        ]);
        $this->throwIfFailed($response, 'Exoscale create');

        $body = $response->json() ?? [];
        $ip = $body['public-ip'] ?? $body['ip-address'] ?? null;

        if (is_array($ip)) {
            $ip = $ip['address'] ?? null;
        }

        return new CloudServerResult(
            id: (string) ($body['id'] ?? ''),
            status: isset($body['state']) ? (string) $body['state'] : null,
            ip: $this->usableIp(is_string($ip) ? $ip : null),
            user: $this->userForImage($request->imageLabel),
        );
    }

    public function delete(string $id, ?string $region = null): void
    {
        if ($region === null || $region === '') {
            throw new RuntimeException('Exoscale zone is required to delete an instance.');
        }

        $response = $this->request('DELETE', $region, '/v2/instance/'.$id);
        $this->throwIfFailed($response, 'Exoscale delete');
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    public function request(string $method, string $zone, string $path, ?array $body = null, ?int $expires = null): Response
    {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            throw new RuntimeException('Exoscale API key and secret are required.');
        }

        $json = $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $expires ??= time() + 600;
        $message = implode("\n", [
            $method.' '.$path,
            $json,
            '',
            '',
            (string) $expires,
        ]);
        $signature = base64_encode(hash_hmac('sha256', $message, $this->apiSecret, true));
        $authorization = 'EXO2-HMAC-SHA256 credential='.$this->apiKey.',expires='.$expires.',signature='.$signature;
        $url = 'https://api-'.$zone.'.exoscale.com'.$path;
        $pending = Http::withHeaders([
            'Authorization' => $authorization,
            'Accept' => 'application/json',
        ])->timeout(20);

        if ($body === null) {
            return $pending->send($method, $url);
        }

        return $pending->withBody($json, 'application/json')->send($method, $url);
    }
}
