<?php

namespace App\Livewire\Server\New;

use App\Enums\ProxyTypes;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Rules\ValidHostname;
use App\Services\Cloud\AdditionalCloudCatalog;
use App\Services\Cloud\AdditionalCloudFactory;
use App\Services\Cloud\AdditionalCloudServerClient;
use App\Services\Cloud\CloudServerRequest;
use App\Services\Cloud\CloudServerResult;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ByAdditionalCloud extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public string $provider;

    public int $current_step = 1;

    #[Locked]
    public Collection $available_tokens;

    #[Locked]
    public $private_keys;

    #[Locked]
    public $limit_reached;

    public ?int $selected_token_id = null;

    public ?string $selectedTokenUuid = null;

    public array $regions = [];

    public array $plans = [];

    public array $images = [];

    public ?string $selected_region = null;

    public ?string $selected_plan = null;

    public ?string $selected_image = null;

    public string $server_name = '';

    public ?int $private_key_id = null;

    public bool $loading_data = false;

    public ?string $provider_data_error = null;

    public function mount(string $provider, ?string $selectedTokenUuid = null): void
    {
        if (! AdditionalCloudCatalog::supports($provider)) {
            abort(404);
        }

        $this->provider = $provider;

        try {
            $this->authorize('create', Server::class);
            $this->authorize('viewAny', CloudProviderToken::class);
            $this->loadTokens();
            $this->selectTokenFromUrl($selectedTokenUuid);
            $this->server_name = generate_random_name();
            $this->private_keys = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->get();

            if ($this->private_keys->count() > 0) {
                $this->private_key_id = $this->private_keys->first()->id;
            }

            if ($this->selectedTokenUuid) {
                $this->current_step = 2;
                $this->loading_data = true;
            }
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    public function getListeners(): array
    {
        return [
            'tokenAdded.'.$this->provider => 'handleTokenAdded',
            'privateKeyCreated' => 'handlePrivateKeyCreated',
            'modalClosed' => 'resetSelection',
        ];
    }

    public function resetSelection(): void
    {
        $this->selected_token_id = null;
        $this->current_step = 1;
    }

    public function loadTokens(): void
    {
        $this->available_tokens = CloudProviderToken::ownedByCurrentTeam()
            ->where('provider', $this->provider)
            ->get();
    }

    public function handleTokenAdded($tokenId): void
    {
        $this->loadTokens();
        $this->selected_token_id = $tokenId;
        $this->nextStep();
    }

    public function handlePrivateKeyCreated($keyId): void
    {
        $this->private_keys = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->get();
        $this->private_key_id = $keyId;
        $this->resetErrorBag('private_key_id');
    }

    protected function rules(): array
    {
        $rules = [
            'selected_token_id' => 'required|integer|exists:cloud_provider_tokens,id',
        ];

        if ($this->current_step === 2) {
            $rules = array_merge($rules, [
                'server_name' => ['required', 'string', 'max:253', new ValidHostname],
                'selected_region' => 'required|string',
                'selected_plan' => 'required|string',
                'selected_image' => 'required|string',
                'private_key_id' => 'required|integer|exists:private_keys,id,team_id,'.currentTeam()->id,
            ]);
        }

        return $rules;
    }

    public function selectToken(int $tokenId): mixed
    {
        $this->selected_token_id = $tokenId;

        return $this->nextStep();
    }

    public function nextStep(): mixed
    {
        $this->validate([
            'selected_token_id' => 'required|integer|exists:cloud_provider_tokens,id',
        ]);

        try {
            if (! $this->selectedTokenUuid) {
                $token = $this->available_tokens->firstWhere('id', $this->selected_token_id);

                if ($token) {
                    return $this->redirectRoute('server.create.token', [
                        'type' => $this->provider,
                        'token_uuid' => $token->uuid,
                    ], navigate: true);
                }
            }

            $this->current_step = 2;
            $this->loading_data = true;
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }

        return null;
    }

    public function loadCatalog(): void
    {
        try {
            $client = $this->client();
            $this->regions = $client->regions();
            $this->selected_region = $this->regions[0]['id'] ?? null;
            $this->refreshPlansAndImages();
        } catch (\Throwable $e) {
            $this->provider_data_error = $e->getMessage();
        } finally {
            $this->loading_data = false;
        }
    }

    public function updatedSelectedRegion(?string $value): void
    {
        if (! $value) {
            return;
        }

        try {
            $this->provider_data_error = null;
            $this->refreshPlansAndImages();
        } catch (\Throwable $e) {
            $this->provider_data_error = $e->getMessage();
        }
    }

    public function submit(): mixed
    {
        $this->validate();

        $client = null;
        $created = null;
        $server = null;

        try {
            $this->authorize('create', Server::class);

            if (Team::serverLimitReached()) {
                return $this->dispatch('error', __('You have reached the server limit for your subscription.'));
            }

            $privateKey = PrivateKey::ownedByCurrentTeam()->findOrFail($this->private_key_id);
            $publicKey = $privateKey->getPublicKey();

            if ($publicKey === '' || str_starts_with($publicKey, 'Error')) {
                return $this->dispatch('error', __('The selected private key could not be read.'));
            }

            $client = $this->client();
            $created = $client->create(new CloudServerRequest(
                name: strtolower(trim($this->server_name)),
                region: (string) $this->selected_region,
                plan: (string) $this->selected_plan,
                image: (string) $this->selected_image,
                publicKey: $publicKey,
                imageLabel: $this->selectedImageLabel(),
            ));

            if ($created->id === '') {
                throw new \RuntimeException('The provider did not return a server id.');
            }

            $server = DB::transaction(function () use ($created): Server {
                $server = Server::create([
                    'name' => strtolower(trim($this->server_name)),
                    'ip' => $created->ip ?? Server::PLACEHOLDER_IP,
                    'user' => $created->user,
                    'port' => 22,
                    'team_id' => currentTeam()->id,
                    'private_key_id' => $this->private_key_id,
                    'cloud_provider_token_id' => $this->selected_token_id,
                    'provider_server_id' => $created->id,
                    'provider_server_status' => $created->status,
                ]);

                $server->proxy->set('status', 'exited');
                $server->proxy->set('type', ProxyTypes::TRAEFIK->value);
                $server->save();

                return $server;
            });

            return redirectRoute($this, 'server.show', [$server->uuid]);
        } catch (\Throwable $e) {
            $this->deleteUntrackedServer($client, $created, $server);

            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.server.new.by-additional-cloud', [
            'providerDefinition' => AdditionalCloudCatalog::find($this->provider),
        ]);
    }

    private function refreshPlansAndImages(): void
    {
        if (! $this->selected_region) {
            $this->plans = [];
            $this->images = [];
            $this->selected_plan = null;
            $this->selected_image = null;

            return;
        }

        $client = $this->client();
        $this->plans = $client->plans($this->selected_region);
        $this->images = $client->images($this->selected_region);
        $this->selected_plan = $this->plans[0]['id'] ?? null;
        $this->selected_image = $this->images[0]['id'] ?? null;
    }

    private function client(): AdditionalCloudServerClient
    {
        $token = $this->available_tokens->firstWhere('id', $this->selected_token_id);

        if (! $token) {
            throw new \RuntimeException('Selected credential was not found.');
        }

        return AdditionalCloudFactory::fromStored($this->provider, $token->token);
    }

    private function selectTokenFromUrl(?string $selectedTokenUuid): void
    {
        if (! $selectedTokenUuid) {
            return;
        }

        $token = $this->available_tokens->firstWhere('uuid', $selectedTokenUuid);

        if (! $token) {
            return;
        }

        $this->selectedTokenUuid = $selectedTokenUuid;
        $this->selected_token_id = $token->id;
    }

    private function selectedImageLabel(): string
    {
        foreach ($this->images as $image) {
            if (($image['id'] ?? null) === $this->selected_image) {
                return (string) ($image['label'] ?? '');
            }
        }

        return '';
    }

    private function deleteUntrackedServer(?AdditionalCloudServerClient $client, ?CloudServerResult $created, ?Server $server): void
    {
        if (! $client || ! $created || $created->id === '' || $server) {
            return;
        }

        try {
            $client->delete($created->id, $this->selected_region);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
