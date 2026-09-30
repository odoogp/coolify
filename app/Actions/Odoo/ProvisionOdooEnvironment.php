<?php

namespace App\Actions\Odoo;

use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Support\OdooDomains;
use App\Support\OdooStaging;
use App\Support\OdooVersion;
use RuntimeException;

class ProvisionOdooEnvironment
{
    public static function run(Environment $environment, StandaloneDocker $destination, string $domain): Service
    {
        $environment->loadMissing('project.odooProfile', 'odooBranch');
        $project = $environment->project;
        $profile = $project?->odooProfile;
        $branch = $environment->odooBranch;

        if ($profile === null || $branch === null) {
            throw new RuntimeException('Odoo is not configured for this environment.');
        }

        if (! OdooStaging::isStagingName($environment->name) && strcasecmp($environment->name, 'production') !== 0) {
            throw new RuntimeException('Odoo services belong on production or staging.');
        }

        if (OdooDomains::host($domain) === '' || OdooDomains::collides($project, $domain, $environment->id)) {
            throw new RuntimeException('Each Odoo environment needs its own domain.');
        }

        $version = $branch->odoo_version ?: $profile->odoo_version;
        if (! in_array($version, OdooVersion::SUPPORTED, true)) {
            $version = '18';
        }

        $workers = max(0, (int) $branch->workers);
        $command = 'odoo --http-interface=0.0.0.0'.($workers > 0 ? ' --workers='.$workers : '');
        $compose = "services:\n  odoo:\n    image: odoo:{$version}\n    command: {$command}\n    volumes:\n      - odoo-web-data:/var/lib/odoo\n      - odoo-extra-addons:/mnt/extra-addons\n  postgresql:\n    image: postgres:16-alpine\nvolumes:\n  odoo-web-data:\n  odoo-extra-addons:\n";

        $service = $environment->services()->where('service_type', 'odoo')->first();
        if ($service === null) {
            $service = Service::create([
                'name' => 'odoo-'.$environment->name,
                'environment_id' => $environment->id,
                'server_id' => $destination->server_id,
                'destination_id' => $destination->id,
                'destination_type' => $destination->getMorphClass(),
                'service_type' => 'odoo',
                'docker_compose_raw' => $compose,
                'docker_compose' => $compose,
                'jupyter_enabled' => (bool) $branch->jupyter_enabled,
            ]);
        }

        $fqdn = 'https://'.OdooDomains::host($domain);
        $odoo = $service->applications()->where('name', 'odoo')->first();
        if ($odoo === null) {
            ServiceApplication::create([
                'service_id' => $service->id,
                'name' => 'odoo',
                'fqdn' => $fqdn,
                'image' => 'odoo:'.$version,
            ]);
        } else {
            $odoo->update(['fqdn' => $fqdn, 'image' => 'odoo:'.$version]);
        }

        $applicationId = $branch->addons_application_id;
        if ($profile->github_app_id !== null && $profile->git_repository !== null && $applicationId === null) {
            $application = Application::create([
                'name' => 'odoo-addons-'.$environment->name,
                'environment_id' => $environment->id,
                'destination_id' => $destination->id,
                'destination_type' => $destination->getMorphClass(),
                'source_id' => $profile->github_app_id,
                'source_type' => GithubApp::class,
                'git_repository' => $profile->git_repository,
                'git_branch' => $branch->git_branch,
                'repository_project_id' => $profile->repository_id,
                'build_pack' => 'dockerfile',
                'ports_exposes' => '8069',
                'is_odoo_addons' => true,
            ]);
            $applicationId = $application->id;
        }

        $branch->update([
            'domain' => $fqdn,
            'odoo_version' => $version,
            'service_id' => $service->id,
            'addons_application_id' => $applicationId,
            'addons_path' => $branch->addons_path ?: '/mnt/extra-addons',
        ]);

        return $service;
    }
}
