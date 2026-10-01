<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Support\OdooGit;
use Illuminate\Http\RedirectResponse;

class OdooEnterController extends Controller
{
    public function __invoke(string $project_uuid, string $environment_uuid, string $service_uuid): RedirectResponse
    {
        $service = Service::query()->where('uuid', $service_uuid)->firstOrFail();
        $environment = $service->environment;
        abort_unless(
            $environment !== null
            && $environment->uuid === $environment_uuid
            && $environment->project?->uuid === $project_uuid,
            404,
        );
        $this->authorize('view', $service);

        $url = OdooGit::enterUrl($service);
        abort_if($url === '', 404);

        return redirect()->away($url);
    }
}
