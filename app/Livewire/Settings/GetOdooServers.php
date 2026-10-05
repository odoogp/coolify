<?php

namespace App\Livewire\Settings;

use App\Models\CloudProviderToken;
use App\Models\GetOdooServerOffer;
use App\Services\GetOdoo\GetOdooServerCatalog;
use Livewire\Component;
use Throwable;

class GetOdooServers extends Component
{
    /** @var array<int, string> */
    public array $markups = [];

    /** @var array<int, bool> */
    public array $available = [];

    public ?int $tokenId = null;

    public function mount(): void
    {
        if (! isInstanceOwner()) {
            $this->redirectRoute('dashboard');

            return;
        }

        $this->tokenId = app(GetOdooServerCatalog::class)->ownerToken()?->id;
        $this->loadOffers();
    }

    public function getListeners(): array
    {
        return [
            'tokenAdded' => 'useAddedToken',
        ];
    }

    public function useAddedToken(int $tokenId, GetOdooServerCatalog $catalog): void
    {
        if (! isInstanceOwner()) {
            return;
        }

        $token = CloudProviderToken::query()->find($tokenId);

        if (! $token instanceof CloudProviderToken) {
            return;
        }

        try {
            $catalog->rememberToken($token);
            $this->tokenId = $token->id;
        } catch (Throwable $exception) {
            $this->dispatch('error', $exception->getMessage());
        }
    }

    public function selectToken(int $tokenId, GetOdooServerCatalog $catalog): void
    {
        if (! isInstanceOwner()) {
            return;
        }

        $token = CloudProviderToken::query()->find($tokenId);

        if (! $token instanceof CloudProviderToken) {
            $this->dispatch('error', __('Choose a Hetzner token. Sold servers are created in that account.'));

            return;
        }

        try {
            $catalog->rememberToken($token);
            $this->tokenId = $token->id;
            $this->dispatch('success', __('Hetzner account saved. Sold servers are created there.'));
        } catch (Throwable $exception) {
            $this->dispatch('error', $exception->getMessage());
        }
    }

    public function refreshConnection(GetOdooServerCatalog $catalog): void
    {
        if (! isInstanceOwner()) {
            return;
        }

        if ($catalog->ownerToken() === null) {
            $this->dispatch('error', __('Choose a Hetzner token. Sold servers are created in that account.'));

            return;
        }

        try {
            $count = $catalog->sync();
            $this->loadOffers();
            $this->dispatch('success', __('Hetzner connection updated. :count servers are available.', ['count' => $count]));
        } catch (Throwable $exception) {
            $this->dispatch('error', $exception->getMessage());
        }
    }

    public function saveOffers(): void
    {
        if (! isInstanceOwner()) {
            return;
        }

        $this->validate([
            'markups.*' => 'numeric|min:0|max:999999',
        ]);

        foreach (GetOdooServerOffer::query()->get() as $offer) {
            $offer->update([
                'markup' => round((float) ($this->markups[$offer->id] ?? 0), 2),
                'available_for_admins' => $offer->in_stock && (bool) ($this->available[$offer->id] ?? false),
            ]);
        }

        $this->loadOffers();
        $this->dispatch('success', __('GetOdoo servers saved.'));
    }

    public function render()
    {
        return view('livewire.settings.getodoo-servers', [
            'offers' => GetOdooServerOffer::query()->orderBy('monthly_price')->orderBy('name')->get(),
            'tokens' => CloudProviderToken::query()
                ->where('team_id', 0)
                ->where('provider', 'hetzner')
                ->orderBy('name')
                ->get(),
            'selectedToken' => app(GetOdooServerCatalog::class)->ownerToken(),
        ]);
    }

    private function loadOffers(): void
    {
        $this->markups = [];
        $this->available = [];

        foreach (GetOdooServerOffer::query()->get() as $offer) {
            $this->markups[$offer->id] = (string) $offer->markup;
            $this->available[$offer->id] = $offer->available_for_admins;
        }
    }
}
