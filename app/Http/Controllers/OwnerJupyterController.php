<?php

namespace App\Http\Controllers;

use App\Support\OdooJupyter;
use Illuminate\Http\RedirectResponse;

class OwnerJupyterController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        abort_unless(isInstanceAdmin(), 403);

        return redirect()->away(OdooJupyter::ensureOwner());
    }
}
