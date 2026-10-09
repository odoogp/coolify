<?php

use App\Support\DetectRequestCountry;
use Illuminate\Http\Request;

it('prefers the forced iso config over request headers', function () {
    config(['constants.getodoo.force_country_iso' => 'MX']);
    $request = Request::create('/');
    $request->headers->set('CF-IPCountry', 'GT');

    expect(DetectRequestCountry::iso($request))->toBe('MX');
});

it('reads cloudflare and cloudfront country headers', function () {
    config(['constants.getodoo.force_country_iso' => null]);

    $cloudflare = Request::create('/');
    $cloudflare->headers->set('CF-IPCountry', 'gt');
    expect(DetectRequestCountry::iso($cloudflare))->toBe('GT');

    $cloudfront = Request::create('/');
    $cloudfront->headers->set('CloudFront-Viewer-Country', 'HN');
    expect(DetectRequestCountry::iso($cloudfront))->toBe('HN');
});

it('rejects unknown and tor country codes', function () {
    expect(DetectRequestCountry::normalize('XX'))->toBeNull()
        ->and(DetectRequestCountry::normalize('T1'))->toBeNull()
        ->and(DetectRequestCountry::normalize('ZZ'))->toBeNull()
        ->and(DetectRequestCountry::normalize('SV'))->toBe('SV');
});
