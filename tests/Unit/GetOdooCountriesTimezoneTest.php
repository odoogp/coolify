<?php

use App\Support\GetOdooCountries;

it('resolves a country iso to its primary timezone', function () {
    expect(GetOdooCountries::timezone('GT'))->toBe('America/Guatemala')
        ->and(GetOdooCountries::timezone('SV'))->toBe('America/El_Salvador')
        ->and(GetOdooCountries::timezone('MX'))->toBe('America/Mexico_City')
        ->and(GetOdooCountries::timezone(null))->toBe('UTC')
        ->and(GetOdooCountries::timezone(''))->toBe('UTC')
        ->and(GetOdooCountries::timezone('ZZ'))->toBe('UTC');
});
