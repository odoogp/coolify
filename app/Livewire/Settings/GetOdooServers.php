<?php

namespace App\Livewire\Settings;

use App\Models\CloudProviderToken;
use App\Models\GetOdooServerOffer;
use App\Services\GetOdoo\GetOdooPrice;
use App\Services\GetOdoo\GetOdooServerCatalog;
use App\Services\GetOdoo\WompiClient;
use Livewire\Component;
use Throwable;

class GetOdooServers extends Component
{
    /** @var array<int, bool> */
    public array $available = [];

    /** @var array<int, string> */
    public array $lineMargins = [];

    /** @var array<int, bool> */
    public array $followsGlobal = [];

    public string $eurUsd = '1.1';

    public string $taxPercent = '19';

    public string $marginPercent = '20';

    public ?int $tokenId = null;

    public string $wompiClientId = '';

    public string $wompiClientSecret = '';

    public bool $wompiReady = false;

    public function mount(): void
    {
        if (! isInstanceOwner()) {
            $this->redirectRoute('dashboard');

            return;
        }

        $this->tokenId = app(GetOdooServerCatalog::class)->ownerToken()?->id;
        $this->loadPricing();
        $this->loadOffers();
        $this->loadWompi();
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

    public function updated(string $property): void
    {
        if ($property === 'marginPercent') {
            foreach ($this->followsGlobal as $id => $follows) {
                if ($follows && ! ($this->available[$id] ?? false)) {
                    $this->lineMargins[$id] = $this->marginPercent;
                }
            }
        }

        if (str_starts_with($property, 'lineMargins.')) {
            $id = (int) substr($property, strlen('lineMargins.'));

            if ($this->available[$id] ?? false) {
                return;
            }

            $value = trim((string) ($this->lineMargins[$id] ?? ''));

            if ($value === '') {
                $this->followsGlobal[$id] = true;
                $this->lineMargins[$id] = $this->marginPercent;
            } else {
                $this->followsGlobal[$id] = false;
            }
        }

        if (str_starts_with($property, 'available.')) {
            $id = (int) substr($property, strlen('available.'));

            if ($this->available[$id] ?? false) {
                $this->followsGlobal[$id] = false;

                if (trim((string) ($this->lineMargins[$id] ?? '')) === '') {
                    $this->lineMargins[$id] = $this->marginPercent;
                }
            }
        }
    }

    public function saveOffers(): void
    {
        if (! isInstanceOwner()) {
            return;
        }

        foreach ($this->lineMargins as $id => $value) {
            if (trim((string) $value) === '') {
                $this->lineMargins[$id] = $this->marginPercent;
                $this->followsGlobal[$id] = ! ($this->available[$id] ?? false);
            }
        }

        $this->validate([
            'eurUsd' => 'required|numeric|min:0.0001|max:100',
            'taxPercent' => 'required|numeric|min:0|max:100',
            'marginPercent' => 'required|numeric|min:0|max:500',
            'lineMargins' => 'array',
            'lineMargins.*' => 'nullable|numeric|min:0|max:500',
        ]);

        instanceSettings()->update([
            'getodoo_eur_usd_rate' => round((float) $this->eurUsd, 4),
            'getodoo_tax_percent' => round((float) $this->taxPercent, 2),
            'getodoo_margin_percent' => round((float) $this->marginPercent, 2),
        ]);

        foreach (GetOdooServerOffer::query()->get() as $offer) {
            $nowAvailable = $offer->in_stock && (bool) ($this->available[$offer->id] ?? false);
            $typed = round((float) ($this->lineMargins[$offer->id] ?? $this->marginPercent), 2);
            $follows = (bool) ($this->followsGlobal[$offer->id] ?? false);

            if ($nowAvailable) {
                $stored = $offer->margin_percent === null ? null : round((float) $offer->margin_percent, 2);
                $marginChanged = $stored === null || abs($stored - $typed) >= 0.01;
                $offer->update([
                    'available_for_admins' => true,
                    'margin_percent' => $typed,
                    'available_since' => ($offer->available_for_admins && ! $marginChanged && $offer->available_since !== null)
                        ? $offer->available_since
                        : now(),
                ]);
            } else {
                $offer->update([
                    'available_for_admins' => false,
                    'margin_percent' => $follows ? null : $typed,
                    'available_since' => null,
                ]);
            }
        }

        $this->loadPricing();
        $this->loadOffers();
        $this->dispatch('success', __('GetOdoo servers saved.'));
    }

    public function saveWompi(): void
    {
        if (! isInstanceOwner()) {
            return;
        }

        $this->validate([
            'wompiClientId' => 'nullable|string|max:255',
            'wompiClientSecret' => 'nullable|string|max:500',
        ]);

        $clientId = trim($this->wompiClientId);
        $secret = trim($this->wompiClientSecret);
        $settings = instanceSettings();

        if ($clientId === '') {
            $settings->update([
                'wompi_client_id' => null,
                'wompi_client_secret' => null,
            ]);
        } else {
            if ($secret === '' && blank($settings->wompi_client_secret)) {
                $this->addError('wompiClientSecret', __('The API secret is required.'));

                return;
            }

            $settings->update([
                'wompi_client_id' => $clientId,
                'wompi_client_secret' => $secret !== '' ? $secret : $settings->wompi_client_secret,
            ]);
        }

        $this->wompiClientSecret = '';
        $this->loadWompi();
        $this->dispatch('success', __('Wompi saved.'));
    }

    public function suggestedUsd(float $netEur, int $offerId): float
    {
        $margin = (float) ($this->lineMargins[$offerId] ?? $this->marginPercent);

        return (new GetOdooPrice((float) $this->eurUsd, (float) $this->taxPercent, $margin))->suggestedUsd($netEur);
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

    private function loadWompi(): void
    {
        $settings = instanceSettings();
        $this->wompiClientId = (string) ($settings->wompi_client_id ?? '');
        $this->wompiClientSecret = '';
        $this->wompiReady = app(WompiClient::class)->configured();
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
        $this->lineMargins = [];
        $this->followsGlobal = [];

        foreach (GetOdooServerOffer::query()->get() as $offer) {
            $this->available[$offer->id] = $offer->available_for_admins && $offer->in_stock;

            if ($offer->margin_percent !== null) {
                $this->lineMargins[$offer->id] = (string) $offer->margin_percent;
                $this->followsGlobal[$offer->id] = false;
            } else {
                $this->lineMargins[$offer->id] = $this->marginPercent;
                $this->followsGlobal[$offer->id] = ! $this->available[$offer->id];
            }
        }
    }
}
