<?php

use App\Support\DetectRequestCountry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['constants.getodoo.force_country_iso' => null]);
    Cache::flush();
});

it('prefers the forced iso config over request headers', function () {
    config(['constants.getodoo.force_country_iso' => 'MX']);
    $request = Request::create('/');
    $request->headers->set('CF-IPCountry', 'GT');

    expect(DetectRequestCountry::iso($request))->toBe('MX');
});

it('reads cloudflare and cloudfront country headers before ip lookup', function () {
    $cloudflare = Request::create('/');
    $cloudflare->headers->set('CF-IPCountry', 'gt');
    expect(DetectRequestCountry::iso($cloudflare))->toBe('GT');

    $cloudfront = Request::create('/');
    $cloudfront->headers->set('CloudFront-Viewer-Country', 'HN');
    expect(DetectRequestCountry::iso($cloudfront))->toBe('HN');
});

it('looks up an approximate country from a public ip when edge headers are missing', function () {
    Http::fake([
        'ip-api.com/json/1.1.1.1*' => Http::response([
            'status' => 'success',
            'countryCode' => 'AU',
        ]),
    ]);

    $request = Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '1.1.1.1']);

    expect(DetectRequestCountry::iso($request))->toBe('AU');
    Http::assertSentCount(1);

    // Cached on the second call.
    expect(DetectRequestCountry::iso($request))->toBe('AU');
    Http::assertSentCount(1);
});

it('skips private ips and rejects unknown country codes', function () {
    expect(DetectRequestCountry::clientIp(Request::create('/', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.5'])))->toBeNull()
        ->and(DetectRequestCountry::normalize('XX'))->toBeNull()
        ->and(DetectRequestCountry::normalize('T1'))->toBeNull()
        ->and(DetectRequestCountry::normalize('ZZ'))->toBeNull()
        ->and(DetectRequestCountry::normalize('SV'))->toBe('SV');
});
