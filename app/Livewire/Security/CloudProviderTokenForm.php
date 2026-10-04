<?php

namespace App\Livewire\Security;

use App\Livewire\Server\CloudProviderToken\Show as ServerCloudProviderTokenShow;
use App\Livewire\Server\New\ByAdditionalCloud;
use App\Livewire\Server\New\ByDigitalOcean;
use App\Livewire\Server\New\ByHetzner;
use App\Models\CloudProviderToken;
use App\Services\Cloud\AdditionalCloudCatalog;
use App\Services\Cloud\AdditionalCloudCredentials;
use App\Services\Cloud\AdditionalCloudFactory;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Http;
use Livewire\Component;

class CloudProviderTokenForm extends Component
{
    use AuthorizesRequests;

    public bool $modal_mode = false;

    public string $provider = 'hetzner';

    public bool $provider_locked = false;

    public string $token = '';

    public string $name = '';

    public ?string $description = null;

    public string $account = '';

    public string $secret = '';

    public string $project = '';

    public function mount(?string $provider = null): void
    {
        $this->provider_locked = filled($provider);
        $this->provider = $provider ?? 'hetzner';

        try {
            $this->authorize('create', CloudProviderToken::class);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    protected function rules(): array
    {
        return [
            'provider' => AdditionalCloudCatalog::providerRule(),
            'token' => 'required|string',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'account' => 'nullable|string|max:255|required_if:provider,upcloud,contabo,exoscale',
            'secret' => 'nullable|string|required_if:provider,contabo',
            'project' => 'nullable|string|max:255|required_if:provider,scaleway,contabo',
        ];
    }

    protected function messages(): array
    {
        return [
            'provider.required' => 'Please select a cloud provider.',
            'provider.in' => 'Invalid cloud provider selected.',
            'token.required' => 'API token is required.',
            'name.required' => 'Token name is required.',
        ];
    }

    private function validateToken(string $provider, string $token): bool
    {
        try {
            if ($provider === 'hetzner') {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$token,
                ])->timeout(10)->get('https://api.hetzner.cloud/v1/servers');

                return $response->successful();
            }

            if ($provider === 'digitalocean') {
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->timeout(10)
                    ->get('https://api.digitalocean.com/v2/account');

                return $response->successful();
            }

            if ($provider === 'vultr') {
                $response = Http::withHeaders([
                    'Authorization' => 'Bearer '.$token,
                ])->timeout(10)->get('https://api.vultr.com/v2/account');

                return $response->successful();
            }

            if (AdditionalCloudCatalog::supports($provider)) {
                return AdditionalCloudFactory::fromStored($provider, $token)->ping();
            }

            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function addToken()
    {
        $this->validate();

        try {
            $storedToken = AdditionalCloudCredentials::pack(
                $this->provider,
                $this->token,
                $this->account,
                $this->secret,
                $this->project,
            );

            // Validate the token with the provider's API
            if (! $this->validateToken($this->provider, $storedToken)) {
                return $this->dispatch('error', __('Invalid API token. Please check your token and try again.'));
            }

            $description = trim($this->description ?? '');

            $savedToken = CloudProviderToken::create([
                'team_id' => currentTeam()->id,
                'provider' => $this->provider,
                'token' => $storedToken,
                'name' => $this->name,
                'description' => $description === '' ? null : $description,
            ]);

            auditLog('ui.cloud_token.created', [
                'team_id' => currentTeam()->id,
                'cloud_token_uuid' => $savedToken->uuid,
                'cloud_token_name' => $savedToken->name,
                'provider' => $savedToken->provider,
            ]);

            $this->reset(['token', 'name', 'description', 'account', 'secret', 'project']);

            // Dispatch event with token ID so parent components can react
            $this->dispatch('tokenAdded', tokenId: $savedToken->id);
            $this->dispatch('tokenAdded', tokenId: $savedToken->id)->to(CloudProviderTokens::class);

            if ($savedToken->provider === 'digitalocean') {
                $this->dispatch('tokenAdded.digitalocean', tokenId: $savedToken->id)->to(ByDigitalOcean::class);
            }

            if ($savedToken->provider === 'hetzner') {
                $this->dispatch('tokenAdded.hetzner', tokenId: $savedToken->id)->to(ByHetzner::class);
                $this->dispatch('tokenAdded.hetzner', tokenId: $savedToken->id)->to(ServerCloudProviderTokenShow::class);
            }

            if (AdditionalCloudCatalog::supports($savedToken->provider)) {
                $this->dispatch('tokenAdded.'.$savedToken->provider, tokenId: $savedToken->id)->to(ByAdditionalCloud::class);
            }

            if ($this->modal_mode) {
                $this->dispatch('close-modal');
            }

            $this->dispatch('success', __('Cloud provider token added successfully.'));
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.security.cloud-provider-token-form');
    }
}
