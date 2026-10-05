<?php

namespace App\Livewire\Settings;

use App\Models\CloudProviderToken;
use App\Models\GetOdooServerOffer;
use App\Services\GetOdoo\GetOdooPrice;
use App\Services\GetOdoo\GetOdooServerCatalog;
use Livewire\Component;
use Throwable;

class GetOdooServers extends Component
{
    /** @var array<int, bool> */
    public array $available = [];

    public string $eurUsd = '1.1';

    public string $taxPercent = '19';

    public string $marginPercent = '20';

    public ?int $tokenId = null;

    public function mount(): void
    {
        if (! isInstanceOwner()) {
            $this->redirectRoute('dashboard');

            return;
        }

        $this->tokenId = app(GetOdooServerCatalog::class)->ownerToken()?->id;
        $this->loadPricing();
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
            $this->loadPricing();
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
            'eurUsd' => 'required|numeric|min:0.0001|max:100',
            'taxPercent' => 'required|numeric|min:0|max:100',
            'marginPercent' => 'required|numeric|min:0|max:500',
        ]);

        instanceSettings()->update([
            'getodoo_eur_usd_rate' => round((float) $this->eurUsd, 4),
            'getodoo_tax_percent' => round((float) $this->taxPercent, 2),
            'getodoo_margin_percent' => round((float) $this->marginPercent, 2),
        ]);

        foreach (GetOdooServerOffer::query()->get() as $offer) {
            $offer->update([
                'available_for_admins' => $offer->in_stock && (bool) ($this->available[$offer->id] ?? false),
            ]);
        }

        $this->loadPricing();
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
            'pricing' => new GetOdooPrice((float) $this->eurUsd, (float) $this->taxPercent, (float) $this->marginPercent),
        ]);
    }

    private function loadPricing(): void
    {
        $pricing = GetOdooPrice::current();
        $this->eurUsd = (string) $pricing->eurUsd;
        $this->taxPercent = (string) $pricing->taxPercent;
        $this->marginPercent = (string) $pricing->marginPercent;
    }

    private function loadOffers(): void
    {
        $this->available = [];

        foreach (GetOdooServerOffer::query()->get() as $offer) {
            $this->available[$offer->id] = $offer->available_for_admins;
        }
    }
}
