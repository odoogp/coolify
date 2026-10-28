<?php

namespace App\Livewire\Settings;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanOrder;
use App\Models\GetOdooServerOffer;
use App\Models\GetOdooServerOrder;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\GetOdoo\GetOdooCheckout;
use App\Services\GetOdoo\N1coClient;
use App\Support\GetOdooBillingOccupancy;
use App\Support\GetOdooPlanBilling;
use App\Support\GetOdooServerBilling;
use App\Support\GpshNotices;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Owner-only Billing Center: organizations, server compute occupancy, and invoices.
 */
class GetOdooPurchases extends Component
{
    /** @var array<int|string, string> */
    public array $prices = [];

    public bool $showInvoiceForm = false;

    public ?string $invoiceTarget = null; // server|plan

    public ?int $invoiceServerId = null;

    public ?int $invoiceTeamId = null;

    public string $invoiceKind = 'once'; // once|recurring

    public string $invoiceAmount = '';

    public string $invoiceDescription = '';

    public bool $invoiceChargeNow = true;

    /** clients | purchases | invoices */
    public string $activeTab = 'clients';

    public string $clientSearch = '';

    /** @var list<string> */
    public array $clientPreferenceFilters = [];

    /** @var list<string> */
    public array $clientBillingFilters = [];

    /** @var list<string> */
    public array $clientPlanFilters = [];

    public function mount(): void
    {
        if (! isInstanceOwner()) {
            $this->redirectRoute('dashboard');

            return;
        }

        if (! in_array($this->activeTab, ['clients', 'purchases', 'invoices'], true)) {
            $this->activeTab = 'clients';
        }

        $this->loadPrices();
    }

    public function setTab(string $tab): void
    {
        abort_unless(isInstanceOwner(), 403);

        if (! in_array($tab, ['clients', 'purchases', 'invoices'], true)) {
            return;
        }

        $this->activeTab = $tab;
    }

    public function toggleClientPreferenceFilter(string $value): void
    {
        abort_unless(isInstanceOwner(), 403);

        if (! in_array($value, ['recurring', 'once'], true)) {
            return;
        }

        $this->clientPreferenceFilters = in_array($value, $this->clientPreferenceFilters, true)
            ? array_values(array_filter($this->clientPreferenceFilters, fn (string $item): bool => $item !== $value))
            : [...$this->clientPreferenceFilters, $value];
    }

    public function toggleClientBillingFilter(string $value): void
    {
        abort_unless(isInstanceOwner(), 403);

        if (! in_array($value, ['billed', 'not_billed'], true)) {
            return;
        }

        $this->clientBillingFilters = in_array($value, $this->clientBillingFilters, true)
            ? array_values(array_filter($this->clientBillingFilters, fn (string $item): bool => $item !== $value))
            : [...$this->clientBillingFilters, $value];
    }

    public function toggleClientPlanFilter(string $value): void
    {
        abort_unless(isInstanceOwner(), 403);

        $value = trim($value);
        if ($value === '') {
            return;
        }

        $this->clientPlanFilters = in_array($value, $this->clientPlanFilters, true)
            ? array_values(array_filter($this->clientPlanFilters, fn (string $item): bool => $item !== $value))
            : [...$this->clientPlanFilters, $value];
    }

    public function clearClientFilters(): void
    {
        abort_unless(isInstanceOwner(), 403);

        $this->clientSearch = '';
        $this->clientPreferenceFilters = [];
        $this->clientBillingFilters = [];
        $this->clientPlanFilters = [];
    }

    public function exportClientsExcel(): StreamedResponse
    {
        abort_unless(isInstanceOwner(), 403);

        $rows = $this->clientExportRows();
        $filename = 'clientes-facturacion-'.now()->format('Y-m-d').'.csv';

        return Response::streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                __('Organization'),
                __('Principal'),
                __('Email'),
                __('Next billing'),
                __('Plan'),
                __('Monthly'),
                __('Preference'),
                __('Invoice date'),
            ]);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
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

    public function openServerInvoiceForm(int $serverId): void
    {
        abort_unless(isInstanceOwner(), 403);

        $server = Server::query()
            ->whereKey($serverId)
            ->where('id', '!=', 0)
            ->whereNotNull('getodoo_offer_id')
            ->firstOrFail();

        $this->resetInvoiceForm();
        $this->invoiceTarget = 'server';
        $this->invoiceServerId = $server->id;
        $this->invoiceAmount = number_format((float) $server->getodoo_monthly_price, 2, '.', '');
        $this->showInvoiceForm = true;
        $this->activeTab = 'invoices';
    }

    public function openPlanInvoiceForm(int $teamId): void
    {
        abort_unless(isInstanceOwner(), 403);

        $team = Team::query()
            ->with('getodooPlan')
            ->whereKey($teamId)
            ->whereNotNull('getodoo_plan_id')
            ->firstOrFail();

        $this->resetInvoiceForm();
        $this->invoiceTarget = 'plan';
        $this->invoiceTeamId = $team->id;
        $this->invoiceAmount = number_format($team->getodooPlanMonthlyAmount(), 2, '.', '');
        $this->showInvoiceForm = true;
        $this->activeTab = 'invoices';
    }

    public function cancelInvoiceForm(): void
    {
        $this->resetInvoiceForm();
    }

    public function submitInvoice(GetOdooCheckout $checkout): void
    {
        abort_unless(isInstanceOwner(), 403);

        $createsInvoiceToday = $this->invoiceKind === 'once' || $this->invoiceChargeNow;

        $this->validate([
            'invoiceTarget' => ['required', 'in:server,plan'],
            'invoiceKind' => ['required', 'in:once,recurring'],
            'invoiceAmount' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            // Client Billing shows this text on the invoice line — required whenever a charge is created.
            'invoiceDescription' => [$createsInvoiceToday ? 'required' : 'nullable', 'string', 'max:255'],
            'invoiceChargeNow' => ['boolean'],
        ]);

        $amount = round((float) $this->invoiceAmount, 2);
        $description = trim($this->invoiceDescription);

        if ($this->invoiceTarget === 'server') {
            $this->submitServerInvoice($checkout, $amount, $description);
        } else {
            $this->submitPlanInvoice($checkout, $amount, $description);
        }

        $this->resetInvoiceForm();
    }

    /**
     * Owner opt-in to recurring for a sold GetOdoo server (no charge today).
     */
    public function enableServerRecurring(int $serverId): void
    {
        abort_unless(isInstanceOwner(), 403);

        $server = Server::query()
            ->whereKey($serverId)
            ->where('id', '!=', 0)
            ->whereNotNull('getodoo_offer_id')
            ->firstOrFail();

        $paidUntil = $server->getodoo_paid_until?->copy()->startOfDay();
        $stillCovered = $paidUntil !== null && $paidUntil->copy()->endOfDay()->isFuture();
        $startsAt = $stillCovered ? $paidUntil : now()->startOfDay();

        $server->update([
            'getodoo_billing_preference' => 'recurring',
            'getodoo_recurring_starts_at' => $startsAt->toDateString(),
        ]);
        GetOdooServerBilling::invalidatePendingRenewals($server, GetOdooPlan::CHARGE_SUBSCRIPTION);

        $this->dispatch('success', __('Recurring billing will start on the next invoice (:date). No charge was made today.', [
            'date' => $startsAt->isoFormat('D MMM YYYY'),
        ]));
    }

    public function disableServerRecurring(int $serverId, N1coClient $n1co): void
    {
        abort_unless(isInstanceOwner(), 403);

        $server = Server::query()
            ->whereKey($serverId)
            ->where('id', '!=', 0)
            ->whereNotNull('getodoo_offer_id')
            ->firstOrFail();

        $subscriptionId = (string) ($server->n1co_subscription_id ?: '');

        if ($subscriptionId !== '') {
            try {
                $n1co->cancelSubscription($subscriptionId);
            } catch (Throwable $exception) {
                report($exception);
            }
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

    /**
     * Owner opt-in to recurring for an organization plan (no charge today).
     */
    public function enablePlanRecurring(int $teamId): void
    {
        abort_unless(isInstanceOwner(), 403);

        $team = Team::query()
            ->whereKey($teamId)
            ->whereNotNull('getodoo_plan_id')
            ->where('id', '!=', 0)
            ->firstOrFail();

        $paidUntil = $team->getodoo_plan_paid_until?->copy()->startOfDay();
        $stillCovered = $paidUntil !== null && $paidUntil->copy()->endOfDay()->isFuture();
        $startsAt = $stillCovered ? $paidUntil : now()->startOfDay();

        $team->update([
            'getodoo_plan_billing_preference' => 'recurring',
            'getodoo_plan_recurring_starts_at' => $startsAt->toDateString(),
        ]);
        GetOdooPlanBilling::invalidatePendingRenewals($team, GetOdooPlan::CHARGE_SUBSCRIPTION);

        $this->dispatch('success', __('Recurring billing will start on the next invoice (:date). No charge was made today.', [
            'date' => $startsAt->isoFormat('D MMM YYYY'),
        ]));
    }

    public function disablePlanRecurring(int $teamId, N1coClient $n1co): void
    {
        abort_unless(isInstanceOwner(), 403);

        $team = Team::query()
            ->whereKey($teamId)
            ->whereNotNull('getodoo_plan_id')
            ->where('id', '!=', 0)
            ->firstOrFail();

        $subscriptionId = (string) ($team->n1co_subscription_id ?: '');
        if ($subscriptionId !== '') {
            try {
                $n1co->cancelSubscription($subscriptionId);
            } catch (Throwable $exception) {
                report($exception);
            }
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

        $this->dispatch('success', __('Recurring billing was turned off. Future invoices can be paid one by one.'));
    }

    public function render(): View
    {
        $invoiceSubject = null;
        $currentMonthly = null;

        if ($this->showInvoiceForm && $this->invoiceTarget === 'server' && $this->invoiceServerId) {
            $invoiceSubject = Server::query()->with('team')->find($this->invoiceServerId);
            $currentMonthly = $invoiceSubject ? (float) $invoiceSubject->getodoo_monthly_price : null;
        }

        if ($this->showInvoiceForm && $this->invoiceTarget === 'plan' && $this->invoiceTeamId) {
            $invoiceSubject = Team::query()->with('getodooPlan')->find($this->invoiceTeamId);
            $currentMonthly = $invoiceSubject instanceof Team ? $invoiceSubject->getodooPlanMonthlyAmount() : null;
        }

        $soldServers = Server::query()
            ->with(['team.members', 'team.getodooPlan', 'getodooOffer'])
            ->where('id', '!=', 0)
            ->whereNotNull('getodoo_offer_id')
            ->orderBy('name')
            ->get();

        $connectedServers = Server::query()
            ->with(['team.members', 'team.getodooPlan'])
            ->where('id', '!=', 0)
            ->where('team_id', '!=', 0)
            ->whereNull('getodoo_offer_id')
            ->orderBy('name')
            ->get();

        $allPlanTeams = Team::query()
            ->with(['getodooPlan', 'members'])
            ->whereNotNull('getodoo_plan_id')
            ->where('id', '!=', 0)
            ->orderBy('name')
            ->get();

        foreach ($allPlanTeams as $planTeam) {
            GetOdooPlanBilling::ensureSignupOrderRecorded($planTeam);
        }

        $principals = $this->principalsByTeam($allPlanTeams);
        $latestPlanInvoiceDates = $this->latestPlanInvoiceDates($allPlanTeams);
        $nextPlanBillingDates = $this->nextPlanBillingDates($allPlanTeams);
        $planTeams = $this->filterPlanTeams($allPlanTeams, $principals, $latestPlanInvoiceDates);
        $clientPlanOptions = $allPlanTeams
            ->map(fn (Team $team): string => trim((string) ($team->getodooPlan?->name ?: '')))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $instanceHost = Server::query()->find(0);
        $instanceTeams = GetOdooBillingOccupancy::teamsOnInstanceHost();

        $orders = GetOdooServerOrder::query()
            ->with(['team', 'user', 'server'])
            ->latest('id')
            ->limit(100)
            ->get();
        $planOrders = GetOdooPlanOrder::query()
            ->with(['team', 'plan'])
            ->latest('id')
            ->limit(100)
            ->get();

        return view('livewire.settings.getodoo-purchases', [
            'soldServerRows' => $this->serverRows($soldServers, kind: 'getodoo'),
            'connectedServerRows' => $this->serverRows($connectedServers, kind: 'connected'),
            'allPlanTeams' => $allPlanTeams,
            'planTeams' => $planTeams,
            'principals' => $principals,
            'latestPlanInvoiceDates' => $latestPlanInvoiceDates,
            'nextPlanBillingDates' => $nextPlanBillingDates,
            'clientPlanOptions' => $clientPlanOptions,
            'clientFilterCount' => count($this->clientPreferenceFilters) + count($this->clientBillingFilters) + count($this->clientPlanFilters),
            'instanceHost' => $instanceHost,
            'instanceTeams' => $instanceTeams,
            'instancePrincipals' => $this->principalsByTeam($instanceTeams),
            'orders' => $orders,
            'planOrders' => $planOrders,
            'metrics' => $this->metrics($allPlanTeams, $soldServers, $connectedServers, $instanceTeams, $orders, $planOrders),
            'invoiceSubject' => $invoiceSubject,
            'currentMonthly' => $currentMonthly,
        ]);
    }

    /**
     * @param  Collection<int, Server>  $servers
     * @return Collection<int, array{server: Server, costUsd: ?float, members: Collection<int, User>, principal: ?User, kind: string}>
     */
    private function serverRows(Collection $servers, string $kind): Collection
    {
        return $servers->map(function (Server $server) use ($kind) {
            $team = $server->team;
            $members = $team?->members ?? collect();
            $principal = $team instanceof Team ? GetOdooBillingOccupancy::principalFor($team) : null;

            return [
                'server' => $server,
                'costUsd' => $kind === 'getodoo' ? GetOdooBillingOccupancy::ownerCostUsd($server) : null,
                'members' => $members,
                'principal' => $principal,
                'kind' => $kind,
            ];
        });
    }

    /**
     * @param  Collection<int, Team>  $planTeams
     * @param  Collection<int, Server>  $soldServers
     * @param  Collection<int, Server>  $connectedServers
     * @param  Collection<int, Team>  $instanceTeams
     * @param  Collection<int, GetOdooServerOrder>  $orders
     * @param  Collection<int, GetOdooPlanOrder>  $planOrders
     * @return array{
     *     organizations: int,
     *     recurring_orgs: int,
     *     monthly_client_usd: float,
     *     sold_servers: int,
     *     connected_servers: int,
     *     instance_clients: int,
     *     owner_cost_usd: float,
     *     pending_invoices: int
     * }
     */
    private function metrics(
        Collection $planTeams,
        Collection $soldServers,
        Collection $connectedServers,
        Collection $instanceTeams,
        Collection $orders,
        Collection $planOrders,
    ): array {
        $monthlyClient = $planTeams->sum(fn (Team $team) => $team->getodooPlanMonthlyAmount())
            + $soldServers->sum(fn (Server $server) => (float) $server->getodoo_monthly_price);

        $ownerCost = $soldServers->sum(fn (Server $server) => (float) (GetOdooBillingOccupancy::ownerCostUsd($server) ?? 0));

        $pending = $orders->where('status', 'awaiting_payment')->count()
            + $planOrders->where('status', 'awaiting_payment')->count();

        return [
            'organizations' => $planTeams->count(),
            'recurring_orgs' => $planTeams->filter(fn (Team $team) => $team->prefersPlanRecurringBilling())->count(),
            'monthly_client_usd' => round((float) $monthlyClient, 2),
            'sold_servers' => $soldServers->count(),
            'connected_servers' => $connectedServers->count(),
            'instance_clients' => $instanceTeams->count(),
            'owner_cost_usd' => round((float) $ownerCost, 2),
            'pending_invoices' => $pending,
        ];
    }

    /**
     * @param  Collection<int, Team>  $teams
     * @return array<int, User|null>
     */
    private function principalsByTeam(Collection $teams): array
    {
        $map = [];
        foreach ($teams as $team) {
            $map[$team->id] = GetOdooBillingOccupancy::principalFor($team);
        }

        return $map;
    }

    /**
     * @param  Collection<int, Team>  $planTeams
     * @return Collection<int, CarbonInterface>
     */
    private function latestPlanInvoiceDates(Collection $planTeams): Collection
    {
        if ($planTeams->isEmpty()) {
            return collect();
        }

        $dates = GetOdooPlanOrder::query()
            ->whereIn('team_id', $planTeams->modelKeys())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['team_id', 'created_at'])
            ->unique(fn (GetOdooPlanOrder $order): int => (int) $order->team_id)
            ->mapWithKeys(fn (GetOdooPlanOrder $order): array => [
                (int) $order->team_id => $order->created_at,
            ]);

        foreach ($planTeams as $team) {
            $teamId = (int) $team->id;
            if ($dates->has($teamId)) {
                continue;
            }

            $fallback = GetOdooPlanBilling::latestPlanInvoiceDate($team);
            if ($fallback instanceof CarbonInterface) {
                $dates->put($teamId, $fallback);
            }
        }

        return $dates;
    }

    /**
     * @param  Collection<int, Team>  $planTeams
     * @return Collection<int, CarbonInterface>
     */
    private function nextPlanBillingDates(Collection $planTeams): Collection
    {
        $dates = collect();

        foreach ($planTeams as $team) {
            $next = GetOdooPlanBilling::nextPlanBillingDate($team);
            if ($next instanceof CarbonInterface) {
                $dates->put((int) $team->id, $next);
            }
        }

        return $dates;
    }

    /**
     * @param  Collection<int, Team>  $planTeams
     * @param  array<int, User|null>  $principals
     * @param  Collection<int, CarbonInterface>  $invoiceDates
     * @return Collection<int, Team>
     */
    private function filterPlanTeams(Collection $planTeams, array $principals, Collection $invoiceDates): Collection
    {
        $search = mb_strtolower(trim($this->clientSearch));

        return $planTeams
            ->filter(function (Team $team) use ($principals, $invoiceDates, $search): bool {
                $principal = $principals[$team->id] ?? null;
                $isRecurring = $team->prefersPlanRecurringBilling();
                $hasInvoice = $invoiceDates->has((int) $team->id);
                $planName = trim((string) ($team->getodooPlan?->name ?: ''));

                if ($search !== '') {
                    $haystack = mb_strtolower(implode(' ', array_filter([
                        $team->name,
                        $principal?->name,
                        $principal?->email,
                        $planName,
                    ])));
                    if (! str_contains($haystack, $search)) {
                        return false;
                    }
                }

                if ($this->clientPreferenceFilters !== []) {
                    $preference = $isRecurring ? 'recurring' : 'once';
                    if (! in_array($preference, $this->clientPreferenceFilters, true)) {
                        return false;
                    }
                }

                if ($this->clientBillingFilters !== []) {
                    $billing = $hasInvoice ? 'billed' : 'not_billed';
                    if (! in_array($billing, $this->clientBillingFilters, true)) {
                        return false;
                    }
                }

                if ($this->clientPlanFilters !== [] && ! in_array($planName, $this->clientPlanFilters, true)) {
                    return false;
                }

                return true;
            })
            ->values();
    }

    /**
     * @return list<list<string>>
     */
    private function clientExportRows(): array
    {
        $planTeams = Team::query()
            ->with(['getodooPlan', 'members'])
            ->whereNotNull('getodoo_plan_id')
            ->where('id', '!=', 0)
            ->orderBy('name')
            ->get();

        foreach ($planTeams as $planTeam) {
            GetOdooPlanBilling::ensureSignupOrderRecorded($planTeam);
        }

        $principals = $this->principalsByTeam($planTeams);
        $invoiceDates = $this->latestPlanInvoiceDates($planTeams);
        $nextDates = $this->nextPlanBillingDates($planTeams);
        $filtered = $this->filterPlanTeams($planTeams, $principals, $invoiceDates);

        return $filtered->map(function (Team $team) use ($principals, $invoiceDates, $nextDates): array {
            $principal = $principals[$team->id] ?? null;
            $next = $nextDates->get((int) $team->id);
            $invoice = $invoiceDates->get((int) $team->id);

            return [
                (string) $team->name,
                (string) ($principal?->name ?: ''),
                (string) ($principal?->email ?: ''),
                $next instanceof CarbonInterface ? $next->isoFormat('D MMM YYYY') : __('Not billed yet'),
                (string) ($team->getodooPlan?->name ?: ''),
                number_format($team->getodooPlanMonthlyAmount(), 2, '.', ''),
                $team->prefersPlanRecurringBilling() ? __('Recurring') : __('One-time'),
                $invoice instanceof CarbonInterface
                    ? $invoice->timezone(config('app.timezone'))->isoFormat('D MMM YYYY')
                    : __('Not billed yet'),
            ];
        })->all();
    }

    private function submitServerInvoice(GetOdooCheckout $checkout, float $amount, string $description): void
    {
        $server = Server::query()
            ->with('team')
            ->whereKey($this->invoiceServerId)
            ->where('id', '!=', 0)
            ->whereNotNull('getodoo_offer_id')
            ->firstOrFail();

        if (! $server->private_key_id) {
            $this->dispatch('error', __('This server has no monthly price.'));

            return;
        }

        $offer = GetOdooServerOffer::query()->find($server->getodoo_offer_id);
        if (! $offer instanceof GetOdooServerOffer) {
            $this->dispatch('error', __('This server has no monthly price.'));

            return;
        }

        if ($this->invoiceKind === 'recurring') {
            $server->update([
                'getodoo_monthly_price' => round((float) $server->getodoo_monthly_price + $amount, 2),
            ]);
            $this->prices[$server->id] = number_format((float) $server->fresh()->getodoo_monthly_price, 2, '.', '');
        }

        if ($this->invoiceKind === 'recurring' && ! $this->invoiceChargeNow) {
            $this->dispatch('success', __('The monthly charge was updated. No invoice was created for today.'));

            return;
        }

        $location = GetOdooServerOrder::query()
            ->where('server_id', $server->id)
            ->where('purpose', 'launch')
            ->latest('id')
            ->value('location') ?: $offer->location;

        // Custom owner invoices are always a one-time checkout for this charge;
        // recurring only changes the monthly total for later renewals.
        $order = GetOdooServerOrder::query()->create([
            'team_id' => $server->team_id,
            'user_id' => auth()->id(),
            'offer_id' => $offer->id,
            'private_key_id' => $server->private_key_id,
            'server_id' => $server->id,
            'server_name' => $server->name,
            'description' => $description !== '' ? $description : null,
            'location' => $location,
            'amount' => $amount,
            'status' => 'awaiting_payment',
            'purpose' => 'renewal',
            'payment_gateway' => $checkout->provider(),
            'charge_mode' => GetOdooPlan::CHARGE_ONCE,
        ]);

        $this->attachServerPaymentLink($order, $checkout);
        $this->notifyClientInvoice($server->team_id, $description !== '' ? $description : $server->name);
        $this->dispatch('success', __('Invoice created. The client can pay it in Billing.'));
    }

    private function submitPlanInvoice(GetOdooCheckout $checkout, float $amount, string $description): void
    {
        $team = Team::query()
            ->with('getodooPlan')
            ->whereKey($this->invoiceTeamId)
            ->whereNotNull('getodoo_plan_id')
            ->firstOrFail();

        $plan = $team->getodooPlan;
        if (! $plan instanceof GetOdooPlan) {
            $this->dispatch('error', __('This plan has no monthly price.'));

            return;
        }

        if ($this->invoiceKind === 'recurring') {
            $team->update([
                'getodoo_plan_monthly_extra' => round((float) $team->getodoo_plan_monthly_extra + $amount, 2),
            ]);
        }

        if ($this->invoiceKind === 'recurring' && ! $this->invoiceChargeNow) {
            $this->dispatch('success', __('The monthly charge was updated. No invoice was created for today.'));

            return;
        }

        $order = GetOdooPlanOrder::query()->create([
            'team_id' => $team->id,
            'user_id' => auth()->id(),
            'plan_id' => $plan->id,
            'amount' => $amount,
            'description' => $description,
            'status' => 'awaiting_payment',
            // Owner-created charges are not plan renewals — client Billing shows the description.
            'purpose' => 'extra',
            'payment_gateway' => $checkout->provider(),
            'charge_mode' => GetOdooPlan::CHARGE_ONCE,
        ]);

        $this->attachPlanPaymentLink($order, $checkout);
        $this->notifyClientInvoice($team->id, $description);
        $this->dispatch('success', __('Invoice created. The client can pay it in Billing.'));
    }

    private function attachServerPaymentLink(GetOdooServerOrder $order, GetOdooCheckout $checkout): void
    {
        if ($checkout->provider() === null) {
            return;
        }

        try {
            // Force once checkout for custom invoices even if server prefers recurring.
            $order->forceFill(['charge_mode' => GetOdooPlan::CHARGE_ONCE])->save();
            $link = $checkout->linkForServer($order);
            $order->update([
                'wompi_link_id' => $link['id'],
                'wompi_link_url' => $link['url'],
                'n1co_plan_id' => $link['plan_id'] ?? null,
                'charge_mode' => GetOdooPlan::CHARGE_ONCE,
                'payment_gateway' => $checkout->provider(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function attachPlanPaymentLink(GetOdooPlanOrder $order, GetOdooCheckout $checkout): void
    {
        if ($checkout->provider() === null) {
            return;
        }

        try {
            $order->forceFill(['charge_mode' => GetOdooPlan::CHARGE_ONCE])->save();
            $link = $checkout->linkForPlanOrder($order);
            $order->update([
                'wompi_link_id' => $link['id'],
                'wompi_link_url' => $link['url'],
                'n1co_plan_id' => $link['plan_id'] ?? null,
                'charge_mode' => GetOdooPlan::CHARGE_ONCE,
                'payment_gateway' => $checkout->provider(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function resetInvoiceForm(): void
    {
        $this->showInvoiceForm = false;
        $this->invoiceTarget = null;
        $this->invoiceServerId = null;
        $this->invoiceTeamId = null;
        $this->invoiceKind = 'once';
        $this->invoiceAmount = '';
        $this->invoiceDescription = '';
        $this->invoiceChargeNow = true;
        $this->resetErrorBag();
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

    private function notifyClientInvoice(?int $teamId, string $resourceName): void
    {
        if ($teamId === null) {
            return;
        }

        GpshNotices::publish(
            null,
            'expiration',
            __('New invoice'),
            __('An invoice for :name is ready to pay.', ['name' => $resourceName]),
            'clients',
            $teamId,
            auth()->id(),
            route('server.billing'),
            __('View my invoices'),
        );
    }
}
