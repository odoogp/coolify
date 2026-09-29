<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $supported = config('app.supported_locales', ['es', 'en']);

        $validated = Validator::make($request->all(), [
            'locale' => ['required', 'string', 'in:'.implode(',', $supported)],
        ])->validate();

        $request->session()->put('locale', $validated['locale']);

        if ($user = $request->user()) {
            $user->forceFill(['locale' => $validated['locale']])->save();
        }

        return back();
    }
}
