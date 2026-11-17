<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DetectRequestCountry
{
    /**
     * ISO 3166-1 alpha-2 from force config, edge headers, or approximate IP geolocation.
     */
    public static function iso(?Request $request = null): ?string
    {
        $forced = self::normalize((string) config('constants.getodoo.force_country_iso', ''));
        if ($forced !== null) {
            return $forced;
        }

        $request ??= request();

        foreach ([
            'CF-IPCountry',
            'CloudFront-Viewer-Country',
            'X-AppEngine-Country',
            'X-Country-Code',
        ] as $header) {
            $iso = self::normalize((string) $request->headers->get($header, ''));
            if ($iso !== null) {
                return $iso;
            }
        }

        $ip = self::clientIp($request);
        if ($ip === null) {
            return null;
        }

        return self::lookupIsoByIp($ip);
    }

    public static function clientIp(?Request $request = null): ?string
    {
        $request ??= request();
        $ip = trim((string) $request->ip());
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        return $ip;
    }

    public static function lookupIsoByIp(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $cacheKey = 'getodoo:geoip:'.sha1($ip);

        try {
            $cached = Cache::get($cacheKey);
            if (is_string($cached)) {
                return self::normalize($cached);
            }
            if ($cached === false) {
                return null;
            }
        } catch (Throwable) {
            // Cache may be unavailable during early boot/tests.
        }

        $iso = self::fetchIsoFromIpApi($ip);

        try {
            Cache::put($cacheKey, $iso ?? false, now()->addDay());
        } catch (Throwable) {
        }

        return $iso;
    }

    public static function normalize(string $value): ?string
    {
        $iso = strtoupper(trim($value));
        if (! preg_match('/^[A-Z]{2}$/', $iso)) {
            return null;
        }

        if (in_array($iso, ['XX', 'T1', 'A1', 'A2', 'O1'], true)) {
            return null;
        }

        if (GetOdooCountries::name($iso) === null) {
            return null;
        }

        return $iso;
    }

    private static function fetchIsoFromIpApi(string $ip): ?string
    {
        try {
            $response = Http::timeout(2)
                ->acceptJson()
                ->get('http://ip-api.com/json/'.$ip, [
                    'fields' => 'status,countryCode',
                ]);

            if (! $response->successful()) {
                return null;
            }

            $payload = $response->json();
            if (! is_array($payload) || ($payload['status'] ?? null) !== 'success') {
                return null;
            }

            return self::normalize((string) ($payload['countryCode'] ?? ''));
        } catch (Throwable $e) {
            Log::debug('IP country lookup failed', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
