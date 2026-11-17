<?php

use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('loads catalog templates inside serviceParser without undefined allServices', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'service_type' => 'portainer',
        'docker_compose_raw' => <<<'YAML'
services:
  portainer:
    image: portainer/portainer-ce:latest
    ports:
      - "9000:9000"
YAML,
    ]);

    $parsed = serviceParser($service->fresh());

    expect($parsed->get('services'))->not->toBeEmpty()
        ->and(file_get_contents(base_path('bootstrap/helpers/parsers.php')))
        ->toContain('$allServices = get_service_templates();');
});
