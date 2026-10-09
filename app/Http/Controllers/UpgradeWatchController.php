<?php

namespace App\Http\Controllers;

use App\Livewire\Upgrade;
use App\Support\GpshNotices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UpgradeWatchController extends Controller
{
    public function status(): JsonResponse
    {
        abort_unless(isInstanceAdmin(), 403);

        return response()->json((new Upgrade)->getUpgradeStatus());
    }

    public function requestFailure(Request $request): JsonResponse
    {
        $message = $request->string('message')->toString();
        GpshNotices::rememberRequestFailure($message);

        return response()->json(['ok' => true]);
    }

    public function log(): JsonResponse
    {
        abort_unless(isInstanceAdmin(), 403);

        return response()->json((new Upgrade)->upgradeLog());
    }
}
