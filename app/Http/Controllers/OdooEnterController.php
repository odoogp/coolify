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

        $login = request()->query('login');
        $login = is_string($login) && preg_match('/^[A-Za-z0-9.@+_-]{1,128}$/', $login) === 1 ? $login : null;
        $url = OdooGit::enterUrl($service, $login);
        if ($url === '') {
            return redirect()->route('project.show', [
                'project_uuid' => $project_uuid,
                'environment' => $environment_uuid,
            ]);
        }

        return redirect()->away($url);
    }
}
