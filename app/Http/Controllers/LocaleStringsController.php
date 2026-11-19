<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Serves lang/{locale}.json as a cacheable script for window.gpshText().
 *
 * The URL carries the file version (?v=), so browsers keep it for a year
 * instead of receiving the whole dictionary inline on every page.
 */
class LocaleStringsController extends Controller
{
    public function __invoke(string $locale): Response
    {
        $supported = config('app.supported_locales', ['es', 'en']);

        abort_unless(in_array($locale, $supported, true) && $locale !== 'en', 404);

        $path = lang_path($locale.'.json');
        abort_unless(is_file($path), 404);

        $strings = json_decode((string) file_get_contents($path), true) ?: [];

        return response('window.gpshStrings = '.json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).';', 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    /**
     * Versioned URL for the current locale, or null when the UI is in English.
     */
    public static function urlFor(string $locale): ?string
    {
        if ($locale === 'en') {
            return null;
        }

        $path = lang_path($locale.'.json');
        if (! is_file($path)) {
            return null;
        }

        return route('locale.strings', ['locale' => $locale, 'v' => filemtime($path).'-'.filesize($path)]);
    }
}
