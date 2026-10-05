<?php

namespace App\Livewire\Server\New;

use App\Models\GetOdooServerOffer;
use App\Models\GetOdooServerOrder;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Services\GetOdoo\GetOdooServerCatalog;
use App\Services\GetOdoo\ProvisionGetOdooServer;
use App\Services\GetOdoo\WompiClient;
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

    public function submit(GetOdooServerCatalog $catalog, WompiClient $wompi, ProvisionGetOdooServer $provisioner)
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

        if ($catalog->ownerToken() === null) {
            return $this->dispatch('error', __('Choose a Hetzner token. Sold servers are created in that account.'));
        }

        $price = $offer->sellPrice($this->location);

        if ($wompi->configured()) {
            return $this->startPayment($wompi, $price);
        }

        try {
            $server = $provisioner->launch(
                $offer,
                $this->server_name,
                (string) $this->location,
                (int) $this->private_key_id,
                (int) currentTeam()->id,
                $price,
            );

            return redirect()->route('server.show', ['server_uuid' => $server->uuid]);
        } catch (Throwable $exception) {
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
            'paysWithWompi' => app(WompiClient::class)->configured(),
            'privateKeyOptions' => $this->private_keys->map(fn ($key) => ['value' => $key->id, 'label' => $key->name])->values()->all(),
        ]);
    }

    private function startPayment(WompiClient $wompi, float $price): mixed
    {
        $order = GetOdooServerOrder::query()->create([
            'team_id' => currentTeam()->id,
            'user_id' => auth()->id(),
            'offer_id' => $offer->id,
            'private_key_id' => $this->private_key_id,
            'server_name' => $this->server_name,
            'location' => $this->location,
            'amount' => $price,
            'status' => 'awaiting_payment',
        ]);

        try {
            $link = $wompi->createServerLink($order);
        } catch (Throwable $exception) {
            $order->delete();
            report($exception);

            return $this->dispatch('error', __('The payment could not be started.'));
        }

        $order->update([
            'wompi_link_id' => $link['id'],
            'wompi_link_url' => $link['url'],
        ]);

        return redirect()->away($link['url']);
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
}
