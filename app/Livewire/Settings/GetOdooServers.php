<?php

namespace App\Livewire\Settings;

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

    public function mount(): void
    {
        if (! isInstanceOwner()) {
            $this->redirectRoute('dashboard');

            return;
        }

        $this->loadOffers();
    }

    public function refreshConnection(GetOdooServerCatalog $catalog): void
    {
        if (! isInstanceOwner()) {
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
            'hasCredential' => app(GetOdooServerCatalog::class)->ownerToken() !== null,
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
