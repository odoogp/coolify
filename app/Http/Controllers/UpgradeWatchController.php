<?php

namespace App\Http\Controllers;

use App\Livewire\Upgrade;
use Illuminate\Http\JsonResponse;

class UpgradeWatchController extends Controller
{
    public function status(): JsonResponse
    {
        abort_unless(isInstanceAdmin(), 403);

        return response()->json((new Upgrade)->getUpgradeStatus());
    }

    public function log(): JsonResponse
    {
        abort_unless(isInstanceAdmin(), 403);

        return response()->json((new Upgrade)->upgradeLog());
    }
}
