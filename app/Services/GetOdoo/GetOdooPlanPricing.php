<?php

namespace App\Services\GetOdoo;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPricingArea;

class GetOdooPlanPricing
{
    /**
     * @return array{base: float, extra_fixed: float, extra_percent: float, amount: float}
     */
    public static function quote(GetOdooPlan $plan, ?GetOdooPricingArea $country = null): array
    {
        $base = round((float) $plan->price, 2);
        $fixed = 0.0;
        $percent = 0.0;

        if ($country instanceof GetOdooPricingArea && $country->isCountry()) {
            $country->loadMissing('parent');
            if ($country->parent instanceof GetOdooPricingArea && $country->parent->is_active) {
                $fixed += (float) $country->parent->extra_fixed;
                $percent += (float) $country->parent->extra_percent;
            }
            if ($country->is_active) {
                $fixed += (float) $country->extra_fixed;
                $percent += (float) $country->extra_percent;
            }
        }

        $amount = round(($base * (1 + ($percent / 100))) + $fixed, 2);

        return [
            'base' => $base,
            'extra_fixed' => round($fixed, 2),
            'extra_percent' => round($percent, 2),
            'amount' => max(0, $amount),
        ];
    }
}
