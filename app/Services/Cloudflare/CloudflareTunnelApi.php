<?php

namespace App\Services\Cloudflare;

use App\Models\InstanceSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Remotely managed Cloudflare tunnels for client servers. Each client server gets
 * one tunnel whose only route is SSH on the server itself, published at a
 * hostname under the owner's tunnel domain (e.g. srv-abc.nodes.getcloud.sh).
 */
class CloudflareTunnelApi
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

    public function __construct(
        private readonly string $apiToken,
        private readonly string $accountId,
        private readonly string $zoneId,
        private readonly string $tunnelDomain,
    ) {}

    public static function fromSettings(?InstanceSettings $settings = null): ?self
    {
        $settings ??= InstanceSettings::query()->find(0);
        if (! $settings instanceof InstanceSettings) {
            return null;
        }

        $token = trim((string) $settings->cloudflare_api_token);
        $account = trim((string) $settings->cloudflare_account_id);
        $zone = trim((string) $settings->cloudflare_zone_id);
        $domain = strtolower(trim((string) $settings->cloudflare_tunnel_domain, " .\t\n\r\0\x0B"));

        if ($token === '' || $account === '' || $zone === '' || $domain === '') {
            return null;
        }

        return new self($token, $account, $zone, $domain);
    }

    public static function isConfigured(): bool
    {
        return self::fromSettings() instanceof self;
    }

    public function hostnameFor(string $label): string
    {
        return $label.'.'.$this->tunnelDomain;
    }

    /**
     * @return array{id: string, token: string}
     */
    public function createTunnel(string $name): array
    {
        $response = $this->request()->post("/accounts/{$this->accountId}/cfd_tunnel", [
            'name' => $name,
            'config_src' => 'cloudflare',
        ]);
        $result = $this->result($response, 'The Cloudflare tunnel could not be created.');

        $id = (string) data_get($result, 'id');
        $token = (string) data_get($result, 'token');
        if ($id === '') {
            throw new RuntimeException('The Cloudflare tunnel could not be created.');
        }
        if ($token === '') {
            $token = (string) $this->result(
                $this->request()->get("/accounts/{$this->accountId}/cfd_tunnel/{$id}/token"),
                'The Cloudflare tunnel token could not be read.',
            );
        }

        return ['id' => $id, 'token' => $token];
    }

    public function routeSsh(string $tunnelId, string $hostname): void
    {
        $this->result($this->request()->put("/accounts/{$this->accountId}/cfd_tunnel/{$tunnelId}/configurations", [
            'config' => [
                'ingress' => [
                    ['hostname' => $hostname, 'service' => 'ssh://localhost:22'],
                    ['service' => 'http_status:404'],
                ],
            ],
        ]), 'The Cloudflare tunnel route could not be saved.');
    }

    public function createDns(string $hostname, string $tunnelId): string
    {
        $result = $this->result($this->request()->post("/zones/{$this->zoneId}/dns_records", [
            'type' => 'CNAME',
            'name' => $hostname,
            'content' => $tunnelId.'.cfargotunnel.com',
            'proxied' => true,
            'comment' => 'GetCloud client server tunnel',
        ]), 'The Cloudflare DNS record could not be created.');

        return (string) data_get($result, 'id');
    }

    /**
     * Best effort: removes the DNS record and the tunnel. Missing ones are ignored.
     */
    public function delete(?string $tunnelId, ?string $dnsRecordId): void
    {
        if (filled($dnsRecordId)) {
            $this->request()->delete("/zones/{$this->zoneId}/dns_records/{$dnsRecordId}");
        }
        if (filled($tunnelId)) {
            $this->request()->delete("/accounts/{$this->accountId}/cfd_tunnel/{$tunnelId}?cascade=true");
        }
    }

    /**
     * Confirms the token can list tunnels in the account and read the zone.
     */
    public function check(): void
    {
        $this->result($this->request()->get("/accounts/{$this->accountId}/cfd_tunnel", ['per_page' => 1]), 'Cloudflare rejected the account or the API token.');
        $this->result($this->request()->get("/zones/{$this->zoneId}"), 'Cloudflare rejected the zone or the API token.');
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withToken($this->apiToken)
            ->acceptJson()
            ->asJson()
            ->timeout(20);
    }

    private function result(Response $response, string $message): mixed
    {
        if (! $response->successful() || $response->json('success') === false) {
            $detail = (string) data_get($response->json(), 'errors.0.message', '');

            throw new RuntimeException($detail !== '' ? $message.' '.$detail : $message);
        }

        return $response->json('result');
    }
}
