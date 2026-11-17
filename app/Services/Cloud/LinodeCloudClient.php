<?php

namespace App\Services\Cloud;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LinodeCloudClient extends AdditionalCloudServerClient
{
    public function __construct(private string $token) {}

    public function ping(): bool
    {
        try {
            return $this->http()->get('/profile')->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function regions(): array
    {
        $regions = [];

        foreach ($this->pages('/regions') as $region) {
            if (($region['status'] ?? 'ok') !== 'ok') {
                continue;
            }

            $id = (string) ($region['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $regions[] = [
                'id' => $id,
                'label' => (string) ($region['label'] ?? $id),
            ];
        }

        return $regions;
    }

    public function plans(string $region): array
    {
        $plans = [];

        foreach ($this->pages('/linode/types') as $type) {
            $class = (string) ($type['class'] ?? '');

            if (in_array($class, ['gpu', 'metal'], true)) {
                continue;
            }

            $id = (string) ($type['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $plans[] = [
                'id' => $id,
                'label' => trim(sprintf(
                    '%s · %s vCPU · %s MB',
                    $type['label'] ?? $id,
                    $type['vcpus'] ?? '?',
                    $type['memory'] ?? '?'
                )),
            ];
        }

        return $plans;
    }

    public function images(string $region): array
    {
        $images = [];

        foreach ($this->pages('/images') as $image) {
            $id = (string) ($image['id'] ?? '');

            if (! str_starts_with($id, 'linode/') || ($image['status'] ?? 'available') !== 'available') {
                continue;
            }

            $label = (string) ($image['label'] ?? $id);

            if ($this->operatingSystemRank($label) === 9) {
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
        $response = $this->http()->post('/linode/instances', [
            'region' => $request->region,
            'type' => $request->plan,
            'image' => $request->image,
            'label' => $this->label($request->name),
            'authorized_keys' => [$request->publicKey],
            'booted' => true,
        ]);
        $this->throwIfFailed($response, 'Linode create');

        $body = $response->json();
        $ipv4 = $body['ipv4'][0] ?? null;

        return new CloudServerResult(
            id: (string) ($body['id'] ?? ''),
            status: isset($body['status']) ? (string) $body['status'] : null,
            ip: $this->usableIp(is_string($ipv4) ? $ipv4 : null),
            user: 'root',
        );
    }

    public function delete(string $id, ?string $region = null): void
    {
        $response = $this->http()->delete('/linode/instances/'.$id);
        $this->throwIfFailed($response, 'Linode delete');
    }

    private function http(): PendingRequest
    {
        if ($this->token === '') {
            throw new RuntimeException('Linode token is required.');
        }

        return Http::withToken($this->token)
            ->acceptJson()
            ->baseUrl('https://api.linode.com/v4')
            ->timeout(20);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pages(string $path): array
    {
        $page = 1;
        $items = [];

        do {
            $response = $this->http()->get($path, [
                'page' => $page,
                'page_size' => 100,
            ]);
            $this->throwIfFailed($response, 'Linode request');
            $json = $response->json();
            $items = array_merge($items, $json['data'] ?? []);
            $pages = (int) ($json['pages'] ?? 1);
            $page++;
        } while ($page <= $pages && $page <= 10);

        return $items;
    }

    private function label(string $name): string
    {
        $label = strtolower($name);
        $label = preg_replace('/[^a-z0-9_-]+/', '-', $label) ?? $label;
        $label = trim($label, '-_');

        if ($label === '') {
            $label = 'gpsh-server';
        }

        return mb_substr($label, 0, 64);
    }
}
