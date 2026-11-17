<?php

namespace App\Support;

use App\Domain\Odoo\OdooDomains;
use App\Models\OdooEnvironmentBranch;
use App\Models\Service;

class OdooRuntime
{
    /**
     * Persist subdomain/workers for an Odoo environment and restart when the host changes.
     *
     * @return array{ok: bool, error?: string, address_changed?: bool, subdomain?: string, workers?: int}
     */
    public static function save(Service $service, string $subdomainInput, int $workersInput): array
    {
        if (! OdooJupyter::isOdooCompose((string) $service->docker_compose_raw)) {
            return ['ok' => false, 'error' => __('This service is not an Odoo stack.')];
        }

        $service->loadMissing(['environment.project.odooProfile', 'environment.odooBranch', 'environment.services.server']);
        $environment = $service->environment;
        $project = $environment?->project;
        $profile = $project?->odooProfile;
        if ($profile === null || $project === null || $environment === null) {
            return ['ok' => false, 'error' => __('Odoo is not enabled for this project.')];
        }

        $subdomain = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($subdomainInput)), '-');
        if (trim($subdomainInput) !== '' && $subdomain === '') {
            return ['ok' => false, 'error' => __('The subdomain can only use letters, numbers, and hyphens.')];
        }

        $previewHost = OdooGit::projectHost(
            $subdomain,
            OdooGit::baseDomain(),
            $environment->name,
            (int) $environment->id,
            $environment->isProduction(),
        );
        if ($previewHost !== '' && OdooDomains::collides($project, 'https://'.$previewHost, (int) $environment->id)) {
            return ['ok' => false, 'error' => __('That address is already used by another environment in this project. Choose a different subdomain.')];
        }

        $workers = max(0, min(32, (int) $workersInput));
        $branch = $environment->odooBranch;
        if ($branch instanceof OdooEnvironmentBranch) {
            $branch->forceFill([
                'subdomain' => $subdomain === '' ? null : $subdomain,
                'workers' => $workers,
            ])->save();
        } else {
            OdooEnvironmentBranch::query()->create([
                'environment_id' => $environment->id,
                'subdomain' => $subdomain === '' ? null : $subdomain,
                'workers' => $workers,
                'status' => 'idle',
            ]);
        }

        if ($environment->isProduction()) {
            $profile->update([
                'subdomain' => $subdomain === '' ? null : $subdomain,
                'workers' => $workers,
            ]);
        }

        $environment->unsetRelation('odooBranch');
        $environment->load(['odooBranch', 'services.server.settings']);
        $addressChanged = false;
        $restartIds = [];
        foreach ($environment->services as $candidate) {
            if (! OdooJupyter::isOdooCompose((string) $candidate->docker_compose_raw)) {
                continue;
            }
            if (OdooGit::applyProjectHost($candidate)) {
                $addressChanged = true;
                $restartIds[] = (int) $candidate->id;
            }
        }
        foreach (array_unique($restartIds) as $restartId) {
            $restart = Service::query()->with(['server.settings'])->find($restartId);
            if ($restart instanceof Service) {
                OdooGit::startIfPossible($restart);
            }
        }

        return [
            'ok' => true,
            'address_changed' => $addressChanged,
            'subdomain' => $subdomain,
            'workers' => $workers,
        ];
    }
}
