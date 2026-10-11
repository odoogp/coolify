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

    $offer->margin_percent = 30;
    $custom = new GetOdooPrice(eurUsd: 1.1, taxPercent: 19, marginPercent: 30);

    expect($offer->sellPrice('fsn1', $price))->toBe($custom->suggestedUsd(4.99));
});

it('explains a published server in words the customer can read', function () {
    expect((new GetOdooServerOffer(['name' => 'cx23']))->customerSummary())
        ->toBe('Shared processor for a site or an application.')
        ->and((new GetOdooServerOffer(['name' => 'cax21', 'architecture' => 'arm']))->customerSummary())
        ->toBe('ARM processor. It does the same work and uses less.')
        ->and((new GetOdooServerOffer(['name' => 'cpx31']))->customerSummary())
        ->toBe('Shared AMD processor, with more room for the application.')
        ->and((new GetOdooServerOffer(['name' => 'ccx23']))->customerSummary())
        ->toBe('Dedicated processor. Its power is not shared.')
        ->and((new GetOdooServerOffer(['name' => 'cx23', 'customer_summary' => ' Para un Odoo pequeño. ']))->customerSummary())
        ->toBe('Para un Odoo pequeño.');
});
