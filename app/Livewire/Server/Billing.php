<?php

namespace App\Livewire\Server;

use App\Actions\Server\DeleteServer;
use App\Jobs\DeleteResourceJob;
use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanOrder;
use App\Models\GetOdooServerOffer;
use App\Models\GetOdooServerOrder;
use App\Models\Server;
use App\Models\Team;
use App\Services\GetOdoo\GetOdooCheckout;
use App\Services\GetOdoo\GetOdooServerCatalog;
use App\Services\GetOdoo\N1coClient;
use App\Services\HetznerService;
use App\Support\GetOdooPlanBilling;
use App\Support\GetOdooServerBilling;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use RuntimeException;
use Throwable;

class Billing extends Component
{
    public function mount(): void
    {
        if (! $this->canAccessBilling()) {
            $this->redirectRoute('dashboard');
        }
    }

    public function pay(int $serverId, GetOdooCheckout $checkout): mixed
    {
        abort_unless(auth()->user()?->canAddServers(), 403);

        $team = currentTeam();
        abort_unless($team, 403);

        $server = Server::query()
            ->where('team_id', $team->id)
            ->where('id', '!=', 0)
            ->whereNotNull('getodoo_offer_id')
            ->findOrFail($serverId);

        if ($checkout->provider() === null) {
            return $this->dispatch('error', __('Wompi is not ready for charges.'));
        }

        $amount = (float) $server->getodoo_monthly_price;

        if ($amount <= 0 || ! $server->private_key_id) {
            return $this->dispatch('error', __('This server has no monthly price.'));
        }

        $expectedChargeMode = GetOdooServerBilling::chargeModeForOrder(
            new GetOdooServerOrder(['purpose' => 'renewal']),
            $server,
        );

        $pending = GetOdooServerOrder::query()
            ->where('server_id', $server->id)
            ->where('team_id', $team->id)
            ->where('purpose', 'renewal')
            ->where('status', 'awaiting_payment')
            ->whereNotNull('wompi_link_url')
            ->latest('id')
            ->first();

        if (
            $pending instanceof GetOdooServerOrder
            && ($pending->payment_gateway ?: 'wompi') === $checkout->provider()
            && $checkout->sameMoney($pending->amount, $amount)
            && ($pending->charge_mode ?: 'once') === $expectedChargeMode
        ) {
            return $this->redirectToServerPayment($pending, (string) $pending->wompi_link_url, (string) ($pending->charge_mode ?: 'once'));
        }

        if ($pending instanceof GetOdooServerOrder) {
            GetOdooServerBilling::invalidatePendingRenewals($server);
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
            'payment_gateway' => $checkout->provider(),
            'charge_mode' => $expectedChargeMode,
        ]);

        try {
            $link = $checkout->linkForServer($order);
        } catch (Throwable $exception) {
            $order->delete();
            report($exception);

            return $this->dispatch('error', __('The payment could not be started.'));
        }

        $order->update([
            'wompi_link_id' => $link['id'],
            'wompi_link_url' => $link['url'],
            'n1co_plan_id' => $link['plan_id'] ?? null,
            'charge_mode' => $link['charge_mode'] ?? GetOdooServerBilling::chargeModeForOrder($order, $server),
        ]);

        return $this->redirectToServerPayment($order->fresh(), $link['url'], $link['charge_mode'] ?? 'once');
    }

    public function payOrder(int $orderId, GetOdooCheckout $checkout): mixed
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

        if ($checkout->provider() === null) {
            return $this->dispatch('error', __('Wompi is not ready for charges.'));
        }

        if ($order->status === 'awaiting_payment' && filled($order->wompi_link_url) && ($order->payment_gateway ?: 'wompi') === $checkout->provider()) {
            return $this->redirectToServerPayment($order, (string) $order->wompi_link_url, (string) ($order->charge_mode ?: 'once'));
        }

        $order->forceFill(['uuid' => new_public_id(), 'payment_gateway' => $checkout->provider()])->save();

        try {
            $link = $checkout->linkForServer($order);
        } catch (Throwable $exception) {
            report($exception);

            return $this->dispatch('error', __('The payment could not be started.'));
        }

        $chargeMode = $link['charge_mode']
            ?? GetOdooServerBilling::chargeModeForOrder($order, $order->server);

        $order->update([
            'status' => 'awaiting_payment',
            'wompi_link_id' => $link['id'],
            'wompi_link_url' => $link['url'],
            'n1co_plan_id' => $link['plan_id'] ?? null,
            'charge_mode' => $chargeMode,
        ]);

        return $this->redirectToServerPayment($order->fresh(), $link['url'], $chargeMode);
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
            $subscriptionId = (string) ($server->n1co_subscription_id ?: '');
            if ($subscriptionId !== '') {
                app(N1coClient::class)->cancelSubscription($subscriptionId);
            }
            $this->removePlan($server);
        } catch (Throwable $exception) {
            report($exception);
            $this->dispatch('error', __('The server could not be deleted.'));

            return;
        }

        $this->dispatch('success', __('The plan was cancelled and the server was deleted.'));
    }

    /**
     * Opt into recurring billing. If the period is still paid, only stores the preference
     * (no charge today). If overdue, opens the n1co subscription checkout immediately.
     */
    public function enableRecurring(int $serverId, GetOdooCheckout $checkout): mixed
    {
        abort_unless(auth()->user()?->canAddServers(), 403);

        $team = currentTeam();
        abort_unless($team, 403);

        $server = Server::query()
            ->where('team_id', $team->id)
            ->where('id', '!=', 0)
            ->whereNotNull('getodoo_offer_id')
            ->findOrFail($serverId);

        $paidUntil = $server->getodoo_paid_until?->copy()->startOfDay();
        $stillCovered = $paidUntil !== null && $paidUntil->copy()->endOfDay()->isFuture();

        if ($stillCovered) {
            $startsAt = $paidUntil;
            $server->update([
                'getodoo_billing_preference' => 'recurring',
                'getodoo_recurring_starts_at' => $startsAt->toDateString(),
            ]);
            GetOdooServerBilling::invalidatePendingRenewals($server, GetOdooPlan::CHARGE_SUBSCRIPTION);

            $this->dispatch('success', __('Recurring billing will start on the next invoice (:date). No charge was made today.', [
                'date' => $startsAt->isoFormat('D MMM YYYY'),
            ]));

            return null;
        }

        // Overdue (or never billed): enroll now via n1co subscription when available.
        $startsAt = now()->startOfDay();
        $server->update([
            'getodoo_billing_preference' => 'recurring',
            'getodoo_recurring_starts_at' => $startsAt->toDateString(),
        ]);
        GetOdooServerBilling::invalidatePendingRenewals($server);

        if ($checkout->provider() === 'n1co') {
            return $this->pay($server->id, $checkout);
        }

        $this->dispatch('success', __('Recurring billing is on. Pay the overdue invoice to finish enrollment.'));

        return null;
    }

    public function disableRecurring(int $serverId, N1coClient $n1co): void
    {
        abort_unless(auth()->user()?->canAddServers(), 403);

        $team = currentTeam();
        abort_unless($team, 403);

        $server = Server::query()
            ->where('team_id', $team->id)
            ->where('id', '!=', 0)
            ->whereNotNull('getodoo_offer_id')
            ->findOrFail($serverId);

        $subscriptionId = (string) ($server->n1co_subscription_id ?: '');

        if ($subscriptionId !== '') {
            $n1co->cancelSubscription($subscriptionId);
        }

        GetOdooServerOrder::query()
            ->where('server_id', $server->id)
            ->whereNotNull('n1co_subscription_id')
            ->where('n1co_subscription_status', 'active')
            ->update(['n1co_subscription_status' => 'cancelled']);

        $server->update([
            'getodoo_billing_preference' => 'once',
            'getodoo_recurring_starts_at' => null,
            'n1co_subscription_status' => $subscriptionId !== '' ? 'cancelled' : null,
        ]);
        GetOdooServerBilling::invalidatePendingRenewals($server, GetOdooPlan::CHARGE_ONCE);

        $this->dispatch('success', __('Recurring billing was turned off. Future invoices can be paid one by one.'));
    }

    public function payPlan(GetOdooCheckout $checkout): mixed
    {
        abort_unless($this->canAccessBilling(), 403);

        $team = currentTeam();
        abort_unless($team instanceof Team && $team->isGetOdooPlanTeam(), 403);

        $plan = $team->getodooPlan;
        abort_unless($plan instanceof GetOdooPlan, 403);

        if ($checkout->provider() === null) {
            return $this->dispatch('error', __('Wompi is not ready for charges.'));
        }

        $amount = $team->getodooPlanMonthlyAmount();
        if ($amount <= 0) {
            return $this->dispatch('error', __('This plan has no monthly price.'));
        }

        $expectedChargeMode = GetOdooPlanBilling::chargeModeForRenewal($team, $plan);
        $pending = GetOdooPlanOrder::query()
            ->where('team_id', $team->id)
            ->where('purpose', 'renewal')
            ->where('status', 'awaiting_payment')
            ->whereNotNull('wompi_link_url')
            ->latest('id')
            ->first();

        if (
            $pending instanceof GetOdooPlanOrder
            && ($pending->payment_gateway ?: 'wompi') === $checkout->provider()
            && $checkout->sameMoney($pending->amount, $amount)
            && ($pending->charge_mode ?: 'once') === $expectedChargeMode
        ) {
            return $this->redirectToPlanPayment($pending, (string) $pending->wompi_link_url, (string) ($pending->charge_mode ?: 'once'));
        }

        if ($pending instanceof GetOdooPlanOrder) {
            GetOdooPlanBilling::invalidatePendingRenewals($team);
        }

        $order = GetOdooPlanOrder::query()->create([
            'team_id' => $team->id,
            'user_id' => auth()->id(),
            'plan_id' => $plan->id,
            'amount' => $amount,
            'status' => 'awaiting_payment',
            'purpose' => 'renewal',
            'payment_gateway' => $checkout->provider(),
            'charge_mode' => $expectedChargeMode,
        ]);

        try {
            $link = $checkout->linkForPlanOrder($order);
        } catch (Throwable $exception) {
            $order->delete();
            report($exception);

            return $this->dispatch('error', __('The payment could not be started.'));
        }

        $chargeMode = $link['charge_mode'] ?? $expectedChargeMode;
        $order->update([
            'wompi_link_id' => $link['id'],
            'wompi_link_url' => $link['url'],
            'n1co_plan_id' => $link['plan_id'] ?? null,
            'charge_mode' => $chargeMode,
        ]);

        return $this->redirectToPlanPayment($order->fresh(), $link['url'], $chargeMode);
    }

    /**
     * Pay a specific plan invoice (row action). Avoids ambiguous header Pay when several are due.
     */
    public function payPlanOrder(int $orderId, GetOdooCheckout $checkout): mixed
    {
        abort_unless($this->canAccessBilling(), 403);

        $team = currentTeam();
        abort_unless($team instanceof Team && $team->isGetOdooPlanTeam(), 403);

        $order = GetOdooPlanOrder::query()
            ->where('team_id', $team->id)
            ->findOrFail($orderId);

        if (! $order->isPayable()) {
            return $this->dispatch('error', __('The payment could not be started.'));
        }

        if ($checkout->provider() === null) {
            return $this->dispatch('error', __('Wompi is not ready for charges.'));
        }

        if ($order->status === 'awaiting_payment' && filled($order->wompi_link_url) && ($order->payment_gateway ?: 'wompi') === $checkout->provider()) {
            return $this->redirectToPlanPayment($order, (string) $order->wompi_link_url, (string) ($order->charge_mode ?: 'once'));
        }

        $order->forceFill(['uuid' => new_public_id(), 'payment_gateway' => $checkout->provider()])->save();

        try {
            $link = $checkout->linkForPlanOrder($order);
        } catch (Throwable $exception) {
            report($exception);

            return $this->dispatch('error', __('The payment could not be started.'));
        }

        $chargeMode = $link['charge_mode'] ?? ($order->charge_mode ?: GetOdooPlanBilling::chargeModeForRenewal($team, $team->getodooPlan));
        $order->update([
            'status' => 'awaiting_payment',
            'wompi_link_id' => $link['id'],
            'wompi_link_url' => $link['url'],
            'n1co_plan_id' => $link['plan_id'] ?? null,
            'charge_mode' => $chargeMode,
        ]);

        return $this->redirectToPlanPayment($order->fresh(), $link['url'], $chargeMode);
    }

    public function enablePlanRecurring(GetOdooCheckout $checkout): mixed
    {
        abort_unless($this->canAccessBilling(), 403);

        $team = currentTeam();
        abort_unless($team instanceof Team && $team->isGetOdooPlanTeam(), 403);

        $paidUntil = $team->getodoo_plan_paid_until?->copy()->startOfDay();
        $stillCovered = $paidUntil !== null && $paidUntil->copy()->endOfDay()->isFuture();

        if ($stillCovered) {
            $team->update([
                'getodoo_plan_billing_preference' => 'recurring',
                'getodoo_plan_recurring_starts_at' => $paidUntil->toDateString(),
            ]);
            GetOdooPlanBilling::invalidatePendingRenewals($team, GetOdooPlan::CHARGE_SUBSCRIPTION);
            $this->refreshBillingTeam($team);
            $this->dispatch('success', __('Recurring billing will start on the next invoice (:date). No charge was made today.', [
                'date' => $paidUntil->isoFormat('D MMM YYYY'),
            ]));

            return null;
        }

        $team->update([
            'getodoo_plan_billing_preference' => 'recurring',
            'getodoo_plan_recurring_starts_at' => now()->startOfDay()->toDateString(),
        ]);
        GetOdooPlanBilling::invalidatePendingRenewals($team);
        $this->refreshBillingTeam($team);

        if ($checkout->provider() === 'n1co') {
            return $this->payPlan($checkout);
        }

        $this->dispatch('success', __('Recurring billing is on. Pay the overdue invoice to finish enrollment.'));

        return null;
    }

    public function disablePlanRecurring(N1coClient $n1co): void
    {
        abort_unless($this->canAccessBilling(), 403);

        $team = currentTeam();
        abort_unless($team instanceof Team && $team->isGetOdooPlanTeam(), 403);

        $subscriptionId = (string) ($team->n1co_subscription_id ?: '');
        if ($subscriptionId !== '') {
            $n1co->cancelSubscription($subscriptionId);
        }

        GetOdooPlanOrder::query()
            ->where('team_id', $team->id)
            ->whereNotNull('n1co_subscription_id')
            ->where('n1co_subscription_status', 'active')
            ->update(['n1co_subscription_status' => 'cancelled']);

        $team->update([
            'getodoo_plan_billing_preference' => 'once',
            'getodoo_plan_recurring_starts_at' => null,
            'n1co_subscription_status' => $subscriptionId !== '' ? 'cancelled' : null,
        ]);
        GetOdooPlanBilling::invalidatePendingRenewals($team, GetOdooPlan::CHARGE_ONCE);
        $this->refreshBillingTeam($team);

        $this->dispatch('success', __('Recurring billing was turned off. Future invoices can be paid one by one.'));
    }

    public function render(): View
    {
        // Always reload the team from the DB — currentTeam() is cached and would keep a stale
        // billing preference after Make recurring / Cancel recurring.
        $teamId = currentTeam()?->id;
        $team = $teamId !== null
            ? Team::query()->with('getodooPlan')->find($teamId)
            : null;

        $servers = Server::query()
            ->with('getodooOffer')
            ->where('team_id', $teamId)
            ->where('id', '!=', 0)
            ->whereNotNull('getodoo_offer_id')
            ->orderBy('name')
            ->get();
        $orders = GetOdooServerOrder::query()
            ->with(['server', 'offer'])
            ->where('team_id', $teamId)
            ->latest('id')
            ->get();

        $planOrders = collect();
        if ($team instanceof Team && $team->isGetOdooPlanTeam()) {
            GetOdooPlanBilling::ensureSignupOrderRecorded($team);
            $planOrders = GetOdooPlanOrder::query()->with('plan')->where('team_id', $team->id)->latest('id')->get();
        }

        return view('livewire.server.billing', [
            'groups' => $this->subscriptionGroups($servers, $orders),
            'planTeam' => $team instanceof Team && $team->isGetOdooPlanTeam() ? $team : null,
            'planOrders' => $planOrders,
        ]);
    }

    private function refreshBillingTeam(Team $team): void
    {
        refreshSession($team->fresh(['getodooPlan']) ?? $team);
    }

    private function canAccessBilling(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        if ($user->canAddServers()) {
            return true;
        }

        $team = currentTeam();

        return $team instanceof Team && $team->isGetOdooPlanTeam() && $user->isAdmin();
    }

    private function redirectToPlanPayment(GetOdooPlanOrder $order, string $paymentUrl, string $chargeMode): mixed
    {
        if ($chargeMode === GetOdooPlan::CHARGE_SUBSCRIPTION && ($order->payment_gateway ?: '') === 'n1co') {
            return redirect()->route('getodoo.plan.order.return', ['order' => $order->uuid]);
        }

        return redirect()->away($paymentUrl);
    }

    /**
     * @param  Collection<int, Server>  $servers
     * @param  Collection<int, GetOdooServerOrder>  $orders
     * @return Collection<int, array{key: string, server: ?Server, name: string, offer: ?string, price: float, orders: Collection<int, GetOdooServerOrder>}>
     */
    private function subscriptionGroups(Collection $servers, Collection $orders): Collection
    {
        $ordersByServer = $orders->filter(fn (GetOdooServerOrder $order) => $order->server_id !== null)->groupBy('server_id');
        $groups = $servers->map(function (Server $server) use ($ordersByServer) {
            return [
                'key' => 'server-'.$server->id,
                'server' => $server,
                'name' => $server->name,
                'offer' => $server->getodooOffer?->description ?: $server->getodooOffer?->name,
                'price' => (float) $server->getodoo_monthly_price,
                'orders' => $ordersByServer->get($server->id, collect())->values(),
            ];
        });

        $attached = $servers->pluck('id');
        $loose = $orders->filter(function (GetOdooServerOrder $order) use ($attached) {
            return $order->server_id === null || ! $attached->contains($order->server_id);
        })->groupBy(fn (GetOdooServerOrder $order) => mb_strtolower(trim($order->server_name)));

        foreach ($loose as $groupOrders) {
            $latest = $groupOrders->first();

            $groups->push([
                'key' => 'name-'.mb_strtolower(trim((string) $latest->server_name)),
                'server' => null,
                'name' => $latest->server_name,
                'offer' => $latest->offer?->description ?: $latest->offer?->name,
                'price' => (float) $latest->amount,
                'orders' => $groupOrders->values(),
            ]);
        }

        return $groups->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
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

    private function redirectToServerPayment(GetOdooServerOrder $order, string $paymentUrl, string $chargeMode): mixed
    {
        // Subscription payment links from n1co do not redirect back. Wait on our return page.
        if ($chargeMode === GetOdooPlan::CHARGE_SUBSCRIPTION && ($order->payment_gateway ?: '') === 'n1co') {
            return redirect()->route('getodoo.n1co.return', ['order' => $order->uuid]);
        }

        return redirect()->away($paymentUrl);
    }
}
