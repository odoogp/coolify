<?php

namespace App\Services\GetOdoo;

use App\Enums\ProxyTypes;
use App\Models\GetOdooServerOffer;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Services\HetznerService;
use RuntimeException;
use Throwable;

class ProvisionGetOdooServer
{
    public function launch(
        GetOdooServerOffer $offer,
        string $name,
        string $location,
        int $privateKeyId,
        int $teamId,
        float $monthlyPrice,
    ): Server {
        $token = app(GetOdooServerCatalog::class)->ownerToken();

        if ($token === null) {
            throw new RuntimeException(__('Choose a Hetzner token. Sold servers are created in that account.'));
        }

        $hetzner = new HetznerService($token->token);
        $remoteId = null;

        try {
            $privateKey = PrivateKey::query()
                ->where('team_id', $teamId)
                ->whereKey($privateKeyId)
                ->firstOrFail();
            $sshKeyId = $this->sshKeyId($hetzner, $privateKey);
            $imageId = app(GetOdooServerCatalog::class)->ubuntuImageId($hetzner, (string) ($offer->architecture ?: 'x86'));
            $created = $hetzner->createServer([
                'name' => strtolower($name),
                'server_type' => $offer->name,
                'image' => $imageId,
                'location' => $location,
                'start_after_create' => true,
                'ssh_keys' => [$sshKeyId],
                'public_net' => [
                    'enable_ipv4' => true,
                    'enable_ipv6' => true,
                ],
            ]);
            $remoteId = $created['id'] ?? null;
            $ip = data_get($created, 'public_net.ipv4.ip') ?: data_get($created, 'public_net.ipv6.ip');

            if (! is_string($ip) || $ip === '' || in_array($ip, ['0.0.0.0', '::', Server::PLACEHOLDER_IP], true)) {
                $ip = Server::PLACEHOLDER_IP;
            }

            $server = Server::create([
                'name' => $name,
                'ip' => $ip,
                'user' => 'root',
                'port' => 22,
                'team_id' => $teamId,
                'private_key_id' => $privateKeyId,
                'cloud_provider_token_id' => $token->id,
                'hetzner_server_id' => $remoteId,
                'hetzner_server_status' => $created['status'] ?? null,
                'getodoo_offer_id' => $offer->id,
                'getodoo_monthly_price' => $monthlyPrice,
            ]);

            $server->proxy->set('status', 'exited');
            $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
            $server->save();

            return $server;
        } catch (Throwable $exception) {
            if (is_numeric($remoteId)) {
                try {
                    $hetzner->deleteServer((int) $remoteId);
                } catch (Throwable $cleanup) {
                    report($cleanup);
                }
            }

            throw $exception;
        }
    }

    private function sshKeyId(HetznerService $hetzner, PrivateKey $privateKey): int
    {
        $fingerprint = PrivateKey::generateMd5Fingerprint($privateKey->private_key);

        foreach ($hetzner->getSshKeys() as $key) {
            if (($key['fingerprint'] ?? null) === $fingerprint) {
                return (int) $key['id'];
            }
        }

        $uploaded = $hetzner->uploadSshKey($privateKey->name, $privateKey->getPublicKey());

        return (int) $uploaded['id'];
    }
}
