<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Livewire\Deployments\Index;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
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

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Shop']);
    $this->application = deploysApplication($this->project, $this->server, 'Storefront');
});

function deploysApplication(Project $project, Server $server, string $name): Application
{
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'name' => $name,
    ]);
}

function deploysRecord(Application $application, Server $server, array $overrides = []): ApplicationDeploymentQueue
{
    return ApplicationDeploymentQueue::create(array_merge([
        'application_id' => $application->id,
        'application_name' => $application->name,
        'deployment_uuid' => 'deploy-'.fake()->unique()->uuid(),
        'deployment_url' => '/deployments/'.fake()->uuid(),
        'server_id' => $server->id,
        'server_name' => $server->name,
        'status' => ApplicationDeploymentStatus::FINISHED->value,
        'pull_request_id' => 0,
    ], $overrides));
}

it('renders the deploys page from the sidebar route', function () {
    deploysRecord($this->application, $this->server, ['commit_message' => 'Ship checkout']);

    $this->get(route('deployments.index'))
        ->assertOk()
        ->assertSee('Ship checkout')
        ->assertSee('Storefront');
});

it('only lists deploys from the current team', function () {
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $otherProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $foreign = deploysApplication($otherProject, $otherServer, 'Foreign App');

    deploysRecord($this->application, $this->server, ['commit_message' => 'Ours']);
    deploysRecord($foreign, $otherServer, ['commit_message' => 'Theirs']);

    Livewire::test(Index::class)
        ->assertSee('Ours')
        ->assertDontSee('Theirs')
        ->assertDontSee('Foreign App');
});

it('filters by status and shows counts', function () {
    deploysRecord($this->application, $this->server, ['commit_message' => 'Green build']);
    deploysRecord($this->application, $this->server, [
        'commit_message' => 'Broken build',
        'status' => ApplicationDeploymentStatus::FAILED->value,
    ]);
    deploysRecord($this->application, $this->server, [
        'commit_message' => 'Building now',
        'status' => ApplicationDeploymentStatus::IN_PROGRESS->value,
    ]);

    Livewire::test(Index::class)
        ->assertViewHas('counts', fn (array $counts) => $counts === ['all' => 3, 'active' => 1, 'failed' => 1, 'finished' => 1])
        ->assertViewHas('polling', true)
        ->call('setFilter', 'failed')
        ->assertSee('Broken build')
        ->assertDontSee('Green build')
        ->assertDontSee('Building now')
        ->call('setFilter', 'active')
        ->assertSee('Building now')
        ->assertDontSee('Broken build');
});

it('searches by commit message and ignores unknown filters', function () {
    deploysRecord($this->application, $this->server, ['commit_message' => 'Fix invoices']);
    deploysRecord($this->application, $this->server, ['commit_message' => 'Update readme']);

    Livewire::test(Index::class)
        ->set('search', 'invoice')
        ->assertSee('Fix invoices')
        ->assertDontSee('Update readme')
        ->call('setFilter', 'bogus')
        ->assertSet('filter', 'all');
});

it('scopes to a locked project', function () {
    $other = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Blog']);
    $blogApp = deploysApplication($other, $this->server, 'Blog App');

    deploysRecord($this->application, $this->server, ['commit_message' => 'Shop deploy']);
    deploysRecord($blogApp, $this->server, ['commit_message' => 'Blog deploy']);

    Livewire::test(Index::class, ['project' => $this->project->uuid])
        ->assertSee('Shop deploy')
        ->assertDontSee('Blog deploy')
        ->set('projectUuid', $other->uuid)
        ->assertSee('Shop deploy')
        ->assertDontSee('Blog deploy');
});

it('returns 404 for a project from another team', function () {
    $otherTeam = Team::factory()->create();
    $foreignProject = Project::factory()->create(['team_id' => $otherTeam->id]);

    Livewire::test(Index::class, ['project' => $foreignProject->uuid])
        ->assertNotFound();
});

it('ignores a foreign project in the url filter', function () {
    $otherTeam = Team::factory()->create();
    $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);
    $foreignProject = Project::factory()->create(['team_id' => $otherTeam->id]);
    $foreign = deploysApplication($foreignProject, $otherServer, 'Foreign App');
    deploysRecord($foreign, $otherServer, ['commit_message' => 'Theirs']);
    deploysRecord($this->application, $this->server, ['commit_message' => 'Ours']);

    $this->get(route('deployments.index', ['project' => $foreignProject->uuid]))
        ->assertOk()
        ->assertDontSee('Theirs');
});

it('pages with load more', function () {
    foreach (range(1, Index::PAGE_SIZE + 2) as $number) {
        deploysRecord($this->application, $this->server, ['commit_message' => "Change {$number}"]);
    }

    Livewire::test(Index::class)
        ->assertViewHas('hasMore', true)
        ->call('loadMore')
        ->assertViewHas('hasMore', false);
});

it('shows the compact panel on the dashboard without filters', function () {
    deploysRecord($this->application, $this->server, ['commit_message' => 'Home deploy']);

    Livewire::test(Index::class, ['compact' => true, 'limit' => 5])
        ->assertSee('Home deploy')
        ->assertDontSee('Search by app, commit or message');
});
