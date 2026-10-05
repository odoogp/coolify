<?php

namespace App\Livewire\Server;

use App\Actions\Server\DeleteServer;
use App\Jobs\DeleteResourceJob;
use App\Models\GetOdooServerOffer;
use App\Models\GetOdooServerOrder;
use App\Models\Server;
use App\Services\GetOdoo\GetOdooServerCatalog;
use App\Services\GetOdoo\WompiClient;
use App\Services\HetznerService;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use RuntimeException;
use Throwable;

class Billing extends Component
{
    public function mount(): void
    {
        if (! auth()->user()?->canAddServers()) {
            $this->redirectRoute('server.index');
        }
    }

    public function pay(int $serverId, WompiClient $wompi): mixed
    {
        abort_unless(auth()->user()?->canAddServers(), 403);

        $team = currentTeam();
        abort_unless($team, 403);

        $server = Server::query()
            ->where('team_id', $team->id)
            ->where('id', '!=', 0)
            ->whereNotNull('getodoo_offer_id')
            ->findOrFail($serverId);

        if (! $wompi->configured()) {
            return $this->dispatch('error', __('Wompi is not ready for charges.'));
        }

        $amount = (float) $server->getodoo_monthly_price;

        if ($amount <= 0 || ! $server->private_key_id) {
            return $this->dispatch('error', __('This server has no monthly price.'));
        }

        $pending = GetOdooServerOrder::query()
            ->where('server_id', $server->id)
            ->where('team_id', $team->id)
            ->where('purpose', 'renewal')
            ->where('status', 'awaiting_payment')
            ->whereNotNull('wompi_link_url')
            ->latest('id')
            ->first();

        if ($pending instanceof GetOdooServerOrder && $wompi->sameMoney($pending->amount, $amount)) {
            return redirect()->away($pending->wompi_link_url);
        }

        $offer = GetOdooServerOffer::query()->find($server->getodoo_offer_id);

        if (! $offer instanceof GetOdooServerOffer) {
            return $this->dispatch('error', __('This server has no monthly price.'));
        }

        $location = GetOdooServerOrder::query()
            ->where('server_id', $server->id)
            ->where('purpose', 'launch')
            ->latest('id')
            ->value('location') ?: $offer->location;

        $order = GetOdooServerOrder::query()->create([
            'team_id' => $team->id,
            'user_id' => auth()->id(),
            'offer_id' => $offer->id,
            'private_key_id' => $server->private_key_id,
            'server_id' => $server->id,
            'server_name' => $server->name,
            'location' => $location,
            'amount' => $amount,
            'status' => 'awaiting_payment',
            'purpose' => 'renewal',
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

    public function payOrder(int $orderId, WompiClient $wompi): mixed
    {
        abort_unless(auth()->user()?->canAddServers(), 403);

        $team = currentTeam();
        abort_unless($team, 403);

        $order = GetOdooServerOrder::query()
            ->where('team_id', $team->id)
            ->findOrFail($orderId);

        if (! in_array($order->status, ['awaiting_payment', 'failed'], true)) {
            return $this->dispatch('error', __('The payment could not be started.'));
        }

        if (! $wompi->configured()) {
            return $this->dispatch('error', __('Wompi is not ready for charges.'));
        }

        if ($order->status === 'awaiting_payment' && filled($order->wompi_link_url)) {
            return redirect()->away($order->wompi_link_url);
        }

        $order->forceFill(['uuid' => new_public_id()])->save();

        try {
            $link = $wompi->createServerLink($order);
        } catch (Throwable $exception) {
            report($exception);

            return $this->dispatch('error', __('The payment could not be started.'));
        }

        $order->update([
            'status' => 'awaiting_payment',
            'wompi_link_id' => $link['id'],
            'wompi_link_url' => $link['url'],
        ]);

        return redirect()->away($link['url']);
    }

    public function cancelOrder(int $orderId): void
    {
        abort_unless(auth()->user()?->canAddServers(), 403);

        $team = currentTeam();
        abort_unless($team, 403);

        $order = GetOdooServerOrder::query()
            ->where('team_id', $team->id)
            ->findOrFail($orderId);

        if (! in_array($order->status, ['awaiting_payment', 'failed'], true)) {
            return;
        }

        $server = $order->server;

        if ($server instanceof Server && (int) $server->id !== 0 && (int) $server->team_id === (int) $team->id && $server->getodoo_offer_id !== null) {
            $this->cancelPlan($server->id);

            return;
        }

        $order->update([
            'status' => 'cancelled',
            'wompi_link_url' => null,
        ]);
        $this->dispatch('success', __('The purchase was cancelled.'));
    }

    public function cancelPlan(int $serverId): void
    {
        abort_unless(auth()->user()?->canAddServers(), 403);

        $team = currentTeam();
        abort_unless($team, 403);

        $server = Server::query()
            ->where('team_id', $team->id)
            ->where('id', '!=', 0)
            ->whereNotNull('getodoo_offer_id')
            ->findOrFail($serverId);

        try {
            $this->removePlan($server);
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatch('error', __('The server could not be deleted.'));

            return;
        }

        $this->dispatch('success', __('The plan was cancelled and the server was deleted.'));
    }

    public function render(): View
    {
        $teamId = currentTeam()?->id;

        return view('livewire.server.billing', [
            'subscriptions' => Server::query()
                ->with('getodooOffer')
                ->where('team_id', $teamId)
                ->where('id', '!=', 0)
                ->whereNotNull('getodoo_offer_id')
                ->orderBy('name')
                ->get(),
            'orders' => GetOdooServerOrder::query()
                ->with(['server', 'user'])
                ->where('team_id', $teamId)
                ->latest('id')
                ->limit(50)
                ->get(),
        ]);
    }

    private function removePlan(Server $server): void
    {
        if ((int) $server->id === 0 || $server->getodoo_offer_id === null) {
            throw new RuntimeException('This server cannot be cancelled.');
        }

        if (is_numeric($server->hetzner_server_id)) {
            $token = app(GetOdooServerCatalog::class)->ownerToken();

            if ($token === null) {
                throw new RuntimeException(__('Choose a Hetzner token. Sold servers are created in that account.'));
            }

            try {
                (new HetznerService($token->token))->deleteServer((int) $server->hetzner_server_id);
            } catch (Throwable $exception) {
                $message = strtolower($exception->getMessage());

                if (! str_contains($message, 'not found') && ! str_contains($message, 'not_found')) {
                    throw $exception;
                }
            }
        }

        GetOdooServerOrder::query()
            ->where('server_id', $server->id)
            ->where('team_id', $server->team_id)
            ->whereIn('status', ['awaiting_payment', 'failed', 'provisioning'])
            ->update([
                'status' => 'cancelled',
                'wompi_link_url' => null,
            ]);

        if ($server->hasDefinedResources()) {
            foreach ($server->definedResources() as $resource) {
                DeleteResourceJob::dispatch($resource);
            }
        }

        $serverId = $server->id;
        $teamId = $server->team_id;
        $server->delete();
        DeleteServer::dispatch($serverId, false, null, null, $teamId, false, null, false, null);
    }
}
