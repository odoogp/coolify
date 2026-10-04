<?php

namespace App\Livewire\Server\New;

use App\Enums\ProxyTypes;
use App\Models\GetOdooServerOffer;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Services\GetOdoo\GetOdooServerCatalog;
use App\Services\HetznerService;
use Illuminate\Support\Collection;
use Livewire\Component;
use Throwable;

class ByGetOdoo extends Component
{
    public ?int $offerId = null;

    public string $server_name = '';

    public $private_key_id = null;

    public ?string $location = null;

    public $private_keys;

    public bool $limit_reached = false;

    public function mount(bool $limit_reached = false): void
    {
        $this->authorize('create', Server::class);
        $this->limit_reached = $limit_reached;
        $this->loadPrivateKeys();

        $first = $this->offers()->first();

        if ($first instanceof GetOdooServerOffer) {
            $this->offerId = $first->id;
            $this->location = $first->location;
        }
    }

    public function getListeners(): array
    {
        return [
            'privateKeyCreated' => 'handlePrivateKeyCreated',
        ];
    }

    public function handlePrivateKeyCreated($keyId): void
    {
        $this->loadPrivateKeys();
        $this->private_key_id = $keyId;
        $this->resetErrorBag('private_key_id');
    }

    public function updatedOfferId(): void
    {
        $offer = $this->offers()->firstWhere('id', $this->offerId);
        $this->location = $offer?->location;
    }

    public function submit(GetOdooServerCatalog $catalog)
    {
        $this->authorize('create', Server::class);

        if ($this->limit_reached || Team::serverLimitReached()) {
            return $this->dispatch('error', __('You have reached the server limit for your subscription.'));
        }

        $offer = $this->offers()->firstWhere('id', $this->offerId);

        if (! $offer instanceof GetOdooServerOffer) {
            $this->addError('offerId', __('This GetOdoo server is no longer available.'));

            return null;
        }

        $locations = collect($offer->locations ?? [])->pluck('location')->filter()->values()->all();

        $this->validate([
            'server_name' => ['required', 'string', 'max:63', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/'],
            'location' => ['required', 'string', 'in:'.implode(',', $locations)],
            'private_key_id' => 'required|integer|exists:private_keys,id,team_id,'.currentTeam()->id,
        ]);

        $token = $catalog->ownerToken();

        if ($token === null) {
            return $this->dispatch('error', __('Add a Hetzner credential on the instance team before updating this connection.'));
        }

        $hetzner = new HetznerService($token->token);
        $remoteId = null;

        try {
            $privateKey = PrivateKey::ownedByCurrentTeam()->findOrFail($this->private_key_id);
            $sshKeyId = $this->sshKeyId($hetzner, $privateKey);
            $imageId = $catalog->ubuntuImageId($hetzner, (string) ($offer->architecture ?: 'x86'));
            $created = $hetzner->createServer([
                'name' => strtolower($this->server_name),
                'server_type' => $offer->name,
                'image' => $imageId,
                'location' => $this->location,
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
                'name' => $this->server_name,
                'ip' => $ip,
                'user' => 'root',
                'port' => 22,
                'team_id' => currentTeam()->id,
                'private_key_id' => $this->private_key_id,
                'cloud_provider_token_id' => $token->id,
                'hetzner_server_id' => $remoteId,
                'hetzner_server_status' => $created['status'] ?? null,
                'getodoo_offer_id' => $offer->id,
                'getodoo_monthly_price' => $offer->sellPrice($this->location),
            ]);

            $server->proxy->set('status', 'exited');
            $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
            $server->save();

            return redirect()->route('server.show', ['server_uuid' => $server->uuid]);
        } catch (Throwable $exception) {
            if (is_numeric($remoteId)) {
                try {
                    $hetzner->deleteServer((int) $remoteId);
                } catch (Throwable $cleanup) {
                    report($cleanup);
                }
            }

            report($exception);

            return $this->dispatch('error', __('The GetOdoo server could not be created.'));
        }
    }

    public function render()
    {
        $offer = $this->offers()->firstWhere('id', $this->offerId);

        return view('livewire.server.new.by-get-odoo', [
            'offers' => $this->offers(),
            'selectedOffer' => $offer,
            'privateKeyOptions' => $this->private_keys->map(fn ($key) => ['value' => $key->id, 'label' => $key->name])->values()->all(),
        ]);
    }

    private function offers(): Collection
    {
        return GetOdooServerOffer::query()->forAdmins()->orderBy('monthly_price')->orderBy('name')->get();
    }

    private function loadPrivateKeys(): void
    {
        $this->private_keys = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->get();

        if ($this->private_keys->count() > 0 && $this->private_key_id === null) {
            $this->private_key_id = $this->private_keys->first()->id;
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
