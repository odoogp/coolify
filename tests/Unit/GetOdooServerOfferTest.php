<?php

use App\Models\GetOdooServerOffer;
use App\Services\GetOdoo\GetOdooPrice;

it('suggests a dollar price from tax, the euro ipv4, the conversion factor, and the percentage', function () {
    $price = new GetOdooPrice(eurUsd: 1.1, taxPercent: 19, marginPercent: 20);

    expect($price->costEur(4.99))->toBe(7.13)
        ->and($price->costUsd(4.99))->toBe(7.84)
        ->and($price->suggestedUsd(4.99))->toBe(9.41);

    $offer = new GetOdooServerOffer([
        'monthly_price' => 4.99,
        'location' => 'fsn1',
        'locations' => [
            ['location' => 'fsn1', 'monthly' => 4.99],
            ['location' => 'hel1', 'monthly' => 5.46],
        ],
    ]);

    expect($offer->sellPrice('fsn1', $price))->toBe(9.41)
        ->and($offer->sellPrice('hel1', $price))->toBe($price->suggestedUsd(5.46));
});
