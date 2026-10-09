<?php

namespace App\Services\GetOdoo;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPricingArea;
use Illuminate\Support\Collection;

class ResolveGetOdooPlansForCountry
{
    /**
     * Plans a buyer in this country may purchase.
     * Country-linked plans win over region-linked; otherwise Rest of the world.
     *
     * @return Collection<int, GetOdooPlan>
     */
    public static function forCountry(?GetOdooPricingArea $country): Collection
    {
        if (! $country instanceof GetOdooPricingArea || ! $country->isCountry() || ! $country->is_active) {
            return collect();
        }

        $countryPlans = self::activePlansLinkedToArea($country->id);
        if ($countryPlans->isNotEmpty()) {
            return $countryPlans;
        }

        $regionId = $country->parent_id !== null ? (int) $country->parent_id : null;
        if ($regionId) {
            $regionPlans = self::activePlansLinkedToArea($regionId);
            if ($regionPlans->isNotEmpty()) {
                return $regionPlans;
            }
        }

        return GetOdooPlan::query()
            ->where('is_active', true)
            ->where('is_rest_of_world', true)
            ->orderBy('price')
            ->orderBy('name')
            ->get();
    }

    public static function planIsAvailable(GetOdooPlan $plan, ?GetOdooPricingArea $country): bool
    {
        if (! $country instanceof GetOdooPricingArea) {
            return false;
        }

        return self::forCountry($country)->contains(
            fn (GetOdooPlan $row): bool => (int) $row->id === (int) $plan->id
        );
    }

    /**
     * @return Collection<int, GetOdooPlan>
     */
    private static function activePlansLinkedToArea(int $pricingAreaId): Collection
    {
        return GetOdooPlan::query()
            ->where('is_active', true)
            ->where('is_rest_of_world', false)
            ->whereHas('pricingAreas', fn ($query) => $query->where('get_odoo_pricing_areas.id', $pricingAreaId))
            ->orderBy('price')
            ->orderBy('name')
            ->get();
    }
}
