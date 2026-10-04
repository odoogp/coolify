<?php

use App\Models\GetOdooServerOffer;

it('adds the markup on top of the location price', function () {
    $offer = new GetOdooServerOffer([
        'monthly_price' => 5.94,
        'markup' => 2,
        'location' => 'fsn1',
        'locations' => [
            ['location' => 'fsn1', 'monthly' => 5.94],
            ['location' => 'hel1', 'monthly' => 6.5],
        ],
    ]);

    expect($offer->sellPrice('fsn1'))->toBe(7.94)
        ->and($offer->sellPrice('hel1'))->toBe(8.5);
});
