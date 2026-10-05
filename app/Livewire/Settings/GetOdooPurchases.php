<?php

namespace App\Livewire\Settings;

use App\Models\GetOdooServerOrder;
use App\Models\Server;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class GetOdooPurchases extends Component
{
    /** @var array<int|string, string> */
    public array $prices = [];

    public function mount(): void
    {
        if (! isInstanceOwner()) {
            $this->redirectRoute('dashboard');

            return;
        }

        $this->loadPrices();
    }

    public function savePrice(int $serverId): void
    {
        abort_unless(isInstanceOwner(), 403);

        $server = Server::query()
            ->whereKey($serverId)
            ->where('id', '!=', 0)
            ->whereNotNull('getodoo_offer_id')
            ->firstOrFail();

        $this->validate([
            'prices.'.$serverId => ['required', 'numeric', 'min:0.01', 'max:100000'],
        ]);

        $server->update([
            'getodoo_monthly_price' => round((float) $this->prices[$serverId], 2),
        ]);

        $this->prices[$serverId] = number_format((float) $server->getodoo_monthly_price, 2, '.', '');
        $this->dispatch('success', __('The monthly price was saved.'));
    }

    public function render(): View
    {
        return view('livewire.settings.getodoo-purchases', [
            'subscriptions' => Server::query()
                ->with(['team', 'getodooOffer'])
                ->where('id', '!=', 0)
                ->whereNotNull('getodoo_offer_id')
                ->orderBy('name')
                ->get(),
            'orders' => GetOdooServerOrder::query()
                ->with(['team', 'user', 'server'])
                ->latest('id')
                ->limit(100)
                ->get(),
        ]);
    }

    private function loadPrices(): void
    {
        $this->prices = Server::query()
            ->where('id', '!=', 0)
            ->whereNotNull('getodoo_offer_id')
            ->pluck('getodoo_monthly_price', 'id')
            ->map(fn ($price) => number_format((float) $price, 2, '.', ''))
            ->all();
    }
}
