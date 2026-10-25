<?php

use App\Livewire\ServiceStartupMonitor;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'created_by' => $this->user->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->service = Service::factory()->create([
        'environment_id' => $this->project->productionEnvironment()->id,
        'server_id' => $this->server->id,
        'name' => 'authentik-2',
        'service_type' => 'authentik',
    ]);
});

it('renders friendly startup stages instead of only raw logs', function () {
    $html = Livewire::test(ServiceStartupMonitor::class, ['service' => $this->service])->html();

    expect($html)->toContain(__('Launch progress'))
        ->and($html)->toContain(__('Preparing configuration'))
        ->and($html)->toContain(__('Starting containers'))
        ->and($html)->toContain(__('Checking health'))
        ->and($html)->toContain(__('Show details'))
        ->and($html)->toContain('data-startup-checkpoints')
        ->and($html)->not->toContain('font-logs');
});

it('toggles optional log details', function () {
    Livewire::test(ServiceStartupMonitor::class, ['service' => $this->service])
        ->assertSet('showLogs', false)
        ->call('toggleLogs')
        ->assertSet('showLogs', true)
        ->assertSee(__('Hide details'));
});

it('wires the service heading to the friendly startup monitor', function () {
    $heading = file_get_contents(resource_path('views/livewire/project/service/heading.blade.php'));
    $view = file_get_contents(resource_path('views/livewire/service-startup-monitor.blade.php'));

    expect($heading)->toContain('service-startup-monitor')
        ->and($heading)->toContain('mobileFullscreen')
        ->and($heading)->not->toContain('<livewire:activity-monitor header="Logs" fullHeight />')
        ->and($view)->toContain('data-startup-checkpoints')
        ->and($view)->toContain('validation-installation-logs')
        ->and($view)->toContain('mt-auto')
        ->and($view)->not->toContain('Working on it');
});
