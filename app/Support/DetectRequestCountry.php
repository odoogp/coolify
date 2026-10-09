<?php

namespace App\Support;

use Illuminate\Http\Request;

class DetectRequestCountry
{
    /**
     * ISO 3166-1 alpha-2 from edge headers or GETODOO_FORCE_COUNTRY_ISO.
     * Cloudflare uses CF-IPCountry; XX/T1 are unknown / Tor.
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

        return null;
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
}
