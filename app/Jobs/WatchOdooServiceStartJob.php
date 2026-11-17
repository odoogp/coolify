<?php

namespace App\Jobs;

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Enums\ProcessStatus;
use App\Models\Service;
use App\Support\GpshNotices;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Activitylog\Models\Activity;

/**
 * Watches a StartService / restart activity and publishes mounted + accessible notices.
 */
class WatchOdooServiceStartJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1300;

    public function __construct(
        public int $serviceId,
        public int $activityId,
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $service = Service::query()->find($this->serviceId);
        $activity = Activity::query()->find($this->activityId);
        if (! $service instanceof Service || ! $activity instanceof Activity || ! $service->supportsOdooJupyter()) {
            return;
        }

        GpshNotices::watchActivity($service, $activity);
    }
}
