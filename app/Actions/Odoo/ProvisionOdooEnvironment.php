<?php

namespace App\Actions\Odoo;

use App\Domain\Odoo\OdooDomains;
use App\Models\Environment;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Support\OdooGit;
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

        if (! $environment->isProduction() && ! $environment->isStaging()) {
            throw new RuntimeException('Odoo services belong on production or staging.');
        }

        if (OdooDomains::host($domain) === '' || OdooDomains::collides($project, $domain, $environment->id)) {
            throw new RuntimeException('Each Odoo environment needs its own domain.');
        }

        $version = $branch->odoo_version ?: $profile->odoo_version;
        if (! \App\Models\OdooComposeTemplate::isConfiguredVersion((string) $version)) {
            $configured = \App\Models\OdooComposeTemplate::configuredVersions();
            $version = $configured[0] ?? '18';
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

        $branch->update([
            'domain' => $fqdn,
            'odoo_version' => $version,
            'service_id' => $service->id,
            'addons_path' => $branch->addons_path ?: '/mnt/extra-addons',
        ]);
        OdooGit::ensureAddonsApplication($environment->fresh() ?? $environment, $service);

        return $service;
    }
}
