<?php

namespace App\Http\Controllers;

use App\Support\OdooJupyter;
use Illuminate\Http\RedirectResponse;

class OwnerJupyterController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        abort_unless(isInstanceOwner(), 403);

        return redirect()->away(OdooJupyter::ensureOwner());
    }
}
