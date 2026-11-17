<?php

namespace App\Services\GetOdoo;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPricingArea;

class GetOdooPlanPricing
{
    /**
     * @return array{base: float, extra_fixed: float, extra_percent: float, promo_price: ?float, amount: float}
     */
    public static function quote(GetOdooPlan $plan, ?GetOdooPricingArea $country = null): array
    {
        $base = round((float) $plan->price, 2);
        $fixed = 0.0;
        $percent = 0.0;
        $promo = null;

        if ($country instanceof GetOdooPricingArea && $country->isCountry() && $country->is_active) {
            $fixed += (float) $country->extra_fixed;
            $percent += (float) $country->extra_percent;

            $areas = $plan->relationLoaded('pricingAreas')
                ? $plan->pricingAreas
                : $plan->pricingAreas()->get();

            $countryPivot = $areas->firstWhere('id', $country->id);
            if ($countryPivot !== null && $countryPivot->pivot?->promo_price !== null) {
                $promo = round((float) $countryPivot->pivot->promo_price, 2);
            } else {
                // IP → country → region: use the region's promo when the plan is regionalized.
                $region = $country->region();
                if ($region instanceof GetOdooPricingArea) {
                    $regionPivot = $areas->firstWhere('id', $region->id);
                    if ($regionPivot !== null && $regionPivot->pivot?->promo_price !== null) {
                        $promo = round((float) $regionPivot->pivot->promo_price, 2);
                    }
                }
            }
        }

        $amount = $promo !== null
            ? max(0, $promo)
            : round(($base * (1 + ($percent / 100))) + $fixed, 2);

        return [
            'base' => $base,
            'extra_fixed' => round($fixed, 2),
            'extra_percent' => round($percent, 2),
            'promo_price' => $promo,
            'amount' => max(0, $amount),
        ];
    }
}
