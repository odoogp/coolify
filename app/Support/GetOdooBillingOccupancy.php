<?php

namespace App\Support;

use App\Models\Application;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Models\Team;
use App\Models\User;
use App\Services\GetOdoo\GetOdooPrice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Owner Billing Center helpers: who occupies a server, and approximate owner cost.
 */
class GetOdooBillingOccupancy
{
    /**
     * Client teams with resources on the instance host (server id 0).
     *
     * @return Collection<int, Team>
     */
    public static function teamsOnInstanceHost(): Collection
    {
        $teamIds = collect();

        $serviceTeamIds = Service::query()
            ->where('server_id', 0)
            ->whereHas('environment.project', fn ($query) => $query->where('team_id', '!=', 0))
            ->with(['environment.project:id,team_id'])
            ->get()
            ->map(fn (Service $service) => (int) data_get($service, 'environment.project.team_id'))
            ->filter(fn (int $id) => $id > 0);

        $teamIds = $teamIds->merge($serviceTeamIds);

        $standaloneIds = StandaloneDocker::query()->where('server_id', 0)->pluck('id');
        $swarmIds = SwarmDocker::query()->where('server_id', 0)->pluck('id');

        if ($standaloneIds->isNotEmpty() || $swarmIds->isNotEmpty()) {
            $appTeamIds = Application::query()
                ->where(function ($query) use ($standaloneIds, $swarmIds) {
                    $query->where(function ($builder) use ($standaloneIds) {
                        $builder->where('destination_type', StandaloneDocker::class)
                            ->whereIn('destination_id', $standaloneIds);
                    })->orWhere(function ($builder) use ($swarmIds) {
                        $builder->where('destination_type', SwarmDocker::class)
                            ->whereIn('destination_id', $swarmIds);
                    });
                })
                ->whereHas('environment.project', fn ($query) => $query->where('team_id', '!=', 0))
                ->with(['environment.project:id,team_id'])
                ->get()
                ->map(fn (Application $application) => (int) data_get($application, 'environment.project.team_id'))
                ->filter(fn (int $id) => $id > 0);

            $teamIds = $teamIds->merge($appTeamIds);
        }

        $ids = $teamIds->unique()->values()->all();

        if ($ids === []) {
            return collect();
        }

        return Team::query()
            ->with(['getodooPlan', 'members' => fn ($query) => $query->orderBy('name')])
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get();
    }

    /**
     * Principal (owner role) for a plan organization, if any.
     */
    public static function principalFor(Team $team): ?User
    {
        $team->loadMissing('members');

        return $team->members->first(fn (User $user) => ($user->pivot->role ?? null) === 'owner');
    }

    /**
     * Approximate monthly cost the instance pays for a GetOdoo-sold server (USD).
     */
    public static function ownerCostUsd(Server $server): ?float
    {
        $offer = $server->getodooOffer;
        if ($offer === null) {
            return null;
        }

        $location = DB::table('get_odoo_server_orders')
            ->where('server_id', $server->id)
            ->where('purpose', 'launch')
            ->orderByDesc('id')
            ->value('location') ?: $offer->location;

        return GetOdooPrice::current()->costUsd($offer->monthlyFor($location ?: null));
    }
}
