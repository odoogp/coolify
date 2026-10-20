<?php

namespace App\Livewire\Settings;

use App\Models\CloudProviderToken;
use App\Models\GetOdooServerOffer;
use App\Services\GetOdoo\GetOdooPrice;
use App\Services\GetOdoo\GetOdooServerCatalog;
use App\Services\GetOdoo\N1coClient;
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

    /** @var array<int, string> */
    public array $summaries = [];

    public string $eurUsd = '1.1';

    public string $taxPercent = '19';

    public string $marginPercent = '20';

    public ?int $tokenId = null;

    public string $wompiClientId = '';

    public string $wompiClientSecret = '';

    public bool $wompiReady = false;

    public string $n1coSecret = '';

    public string $n1coWebhookSecret = '';

    public bool $n1coSandbox = true;

    public bool $n1coCharges = false;

    public bool $n1coReady = false;

    public string $n1coLocationId = '';

    public string $n1coServerChargeMode = 'once';

    public string $n1coClientId = '';

    public string $n1coClientSecret = '';

    public string $bankTransferInstructions = '';

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
        $this->loadN1co();
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
            'summaries' => 'array',
            'summaries.*' => 'nullable|string|max:280',
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
            $summary = trim((string) ($this->summaries[$offer->id] ?? ''));

            if ($nowAvailable) {
                $stored = $offer->margin_percent === null ? null : round((float) $offer->margin_percent, 2);
                $marginChanged = $stored === null || abs($stored - $typed) >= 0.01;
                $offer->update([
                    'available_for_admins' => true,
                    'margin_percent' => $typed,
                    'available_since' => ($offer->available_for_admins && ! $marginChanged && $offer->available_since !== null)
                        ? $offer->available_since
                        : now(),
                    'customer_summary' => $summary === '' ? null : $summary,
                ]);
            } else {
                $offer->update([
                    'available_for_admins' => false,
                    'margin_percent' => $follows ? null : $typed,
                    'available_since' => null,
                    'customer_summary' => $summary === '' ? null : $summary,
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

    public function saveN1co(): void
    {
        if (! isInstanceOwner()) {
            return;
        }

        $this->validate([
            'n1coSecret' => 'nullable|string|max:500',
            'n1coWebhookSecret' => 'nullable|string|max:500',
            'n1coSandbox' => 'boolean',
            'n1coCharges' => 'boolean',
            'n1coLocationId' => ['nullable', 'regex:/^$|^[1-9][0-9]*$/'],
            'n1coServerChargeMode' => 'required|in:once,subscription',
            'n1coClientId' => 'nullable|string|max:255',
            'n1coClientSecret' => 'nullable|string|max:500',
            'bankTransferInstructions' => 'nullable|string|max:2000',
        ]);

        $settings = instanceSettings();
        $secret = trim($this->n1coSecret);
        $webhook = trim($this->n1coWebhookSecret);
        $clientSecret = trim($this->n1coClientSecret);
        $secret = $secret !== '' ? $secret : (string) ($settings->n1co_secret ?? '');
        $webhook = $webhook !== '' ? $webhook : (string) ($settings->n1co_webhook_secret ?? '');

        if ($this->n1coServerChargeMode === 'subscription' && trim($this->n1coLocationId) === '') {
            $this->addError('n1coLocationId', __('The n1co branch id is required for a monthly subscription.'));

            return;
        }

        if ($this->n1coServerChargeMode === 'subscription') {
            $clientId = trim($this->n1coClientId);
            $storedSecret = trim((string) ($settings->n1co_client_secret ?? ''));

            if ($clientId === '' || ($clientSecret === '' && $storedSecret === '')) {
                if ($clientId === '') {
                    $this->addError('n1coClientId', __('The API client id is required for a monthly subscription.'));
                }

                if ($clientSecret === '' && $storedSecret === '') {
                    $this->addError('n1coClientSecret', __('The API client secret is required for a monthly subscription.'));
                }

                return;
            }
        }

        if ($this->n1coCharges && ($secret === '' || $webhook === '')) {
            if ($secret === '') {
                $this->addError('n1coSecret', __('The secret key is required.'));
            }

            if ($webhook === '') {
                $this->addError('n1coWebhookSecret', __('The webhook secret is required.'));
            }

            return;
        }

        $settings->update([
            'n1co_secret' => $secret !== '' ? $secret : null,
            'n1co_webhook_secret' => $webhook !== '' ? $webhook : null,
            'n1co_sandbox' => $this->n1coSandbox,
            'n1co_charges' => $this->n1coCharges,
            'n1co_location_id' => trim($this->n1coLocationId) === '' ? null : (int) $this->n1coLocationId,
            'n1co_server_charge_mode' => $this->n1coServerChargeMode === 'subscription' ? 'subscription' : 'once',
            'n1co_client_id' => trim($this->n1coClientId) !== '' ? trim($this->n1coClientId) : null,
            'n1co_client_secret' => $clientSecret !== '' ? $clientSecret : ($settings->n1co_client_secret ?: null),
            'bank_transfer_instructions' => trim($this->bankTransferInstructions) !== '' ? trim($this->bankTransferInstructions) : null,
        ]);

        $this->n1coSecret = '';
        $this->n1coWebhookSecret = '';
        $this->n1coClientSecret = '';
        $this->loadN1co();
        $this->dispatch('success', __('n1co saved.'));
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

    private function loadN1co(): void
    {
        $settings = instanceSettings();
        $this->n1coSecret = '';
        $this->n1coWebhookSecret = '';
        $this->n1coSandbox = (bool) ($settings->n1co_sandbox ?? true);
        $this->n1coCharges = (bool) ($settings->n1co_charges ?? false);
        $this->n1coLocationId = $settings->n1co_location_id ? (string) $settings->n1co_location_id : '';
        $this->n1coServerChargeMode = $settings->n1co_server_charge_mode ?: 'once';
        $this->n1coClientId = (string) ($settings->n1co_client_id ?? '');
        $this->n1coClientSecret = '';
        $this->bankTransferInstructions = (string) ($settings->bank_transfer_instructions ?? '');
        $this->n1coReady = app(N1coClient::class)->charges();
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
        $this->summaries = [];

        foreach (GetOdooServerOffer::query()->get() as $offer) {
            $this->available[$offer->id] = $offer->available_for_admins && $offer->in_stock;
            $this->summaries[$offer->id] = (string) ($offer->customer_summary ?? '');

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
