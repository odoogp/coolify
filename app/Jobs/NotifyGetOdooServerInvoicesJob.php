<?php

namespace App\Jobs;

use App\Models\Server;
use App\Support\GpshNotices;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyGetOdooServerInvoicesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3500;

    public function handle(): void
    {
        $today = now()->startOfDay();
        $dueSoonUntil = $today->copy()->addDays(7);

        Server::query()
            ->where('id', '!=', 0)
            ->whereNotNull('getodoo_offer_id')
            ->whereNotNull('getodoo_paid_until')
            ->whereNotNull('team_id')
            ->orderBy('id')
            ->chunkById(100, function ($servers) use ($today, $dueSoonUntil): void {
                foreach ($servers as $server) {
                    if (! $server instanceof Server || $server->getodoo_paid_until === null) {
                        continue;
                    }

                    $paidUntil = $server->getodoo_paid_until->copy()->startOfDay();

                    if ($paidUntil->lt($today)) {
                        GpshNotices::announceServerInvoice($server, 'overdue');

                        continue;
                    }

                    if ($paidUntil->lte($dueSoonUntil)) {
                        GpshNotices::announceServerInvoice($server, 'due_soon');
                    }
                }
            });
    }
}
