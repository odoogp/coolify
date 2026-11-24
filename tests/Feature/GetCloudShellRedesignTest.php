<?php

use App\Actions\Service\StartService;
use App\Livewire\Dashboard;
use App\Livewire\Project\Show;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create(['name' => 'Ariel Méndez']);
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);
});

function redesignStoppedService(Team $team): Service
{
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $server->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'test-docker'],
    ));
    $project = Project::factory()->create(['team_id' => $team->id, 'name' => 'OdooGP']);
    $environment = $project->environments()->first() ?? Environment::factory()->create(['project_id' => $project->id]);
    $uuid = 'kfkwdapyzvxvqty3gz8r3yov';

    return Service::factory()->create([
        'uuid' => $uuid,
        'name' => 'authentik-'.$uuid,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'environment_id' => $environment->id,
    ]);
}

it('groups the less used sidebar items under a collapsible More', function () {
    $navbar = file_get_contents(resource_path('views/components/navbar.blade.php'));

    expect($navbar)
        ->toContain('data-nav-more')
        ->toContain("{{ __('More') }}")
        ->toContain("{{ __('Work') }}")
        ->not->toContain("{{ __('Manage') }}")
        ->and(strpos($navbar, 'data-nav-more'))->toBeLessThan(strpos($navbar, "__('Sources')"))
        ->and(strpos($navbar, 'data-nav-more'))->toBeGreaterThan(strpos($navbar, "__('Billing')"));
});

it('puts search and New in the top bar and drops the repeated footer', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
    $search = file_get_contents(resource_path('views/livewire/global-search.blade.php'));

    expect($layout)
        ->toContain('data-topbar-actions')
        ->toContain("\$dispatch('open-global-search', { query: 'new' })")
        ->not->toContain('<footer class="mt-10 text-center')
        ->and($search)->toContain("openModal(event?.detail?.query || '')");
});

it('keeps the animated sign-out form in the user menu', function () {
    expect(file_get_contents(resource_path('views/components/top-user-menu.blade.php')))->toContain('data-logout');
});

it('shows the status strip and lists a stopped service under needs attention with a readable name', function () {
    redesignStoppedService($this->team);

    Livewire::test(Dashboard::class)
        ->assertSee('Good', false)
        ->assertSeeHtml('data-status-strip')
        ->assertSeeHtml('data-needs-attention')
        ->assertSee('Authentik')
        ->assertSee('OdooGP')
        ->assertSee(__('Start'));
});

it('starts a stopped service from the dashboard', function () {
    $service = redesignStoppedService($this->team);
    StartService::shouldRun()->once();

    Livewire::test(Dashboard::class)
        ->call('startResource', 'service', $service->uuid)
        ->assertDispatched('success');
});

it('does not let a member start resources from the dashboard', function () {
    $service = redesignStoppedService($this->team);
    $member = User::factory()->create();
    $this->team->members()->attach($member->id, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);
    StartService::shouldRun()->never();

    Livewire::test(Dashboard::class)
        ->call('startResource', 'service', $service->uuid)
        ->assertNotDispatched('success');
});

it('lists every resource of the project in a dense table', function () {
    $service = redesignStoppedService($this->team);
    $project = $service->environment->project;

    Livewire::test(Show::class, ['project_uuid' => $project->uuid])
        ->assertSeeHtml('data-project-resources')
        ->assertSee('Authentik')
        ->assertSee($service->name);
});
