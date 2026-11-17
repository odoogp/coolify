<?php

use App\Livewire\Boarding\Index;
use App\Models\GetOdooPlan;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], ['fqdn' => null]));

    $plan = GetOdooPlan::query()->create([
        'name' => 'Welcome plan',
        'price' => 0,
        'currency' => 'USD',
        'is_active' => true,
        'is_rest_of_world' => true,
        'can_add_servers' => true,
    ]);
    $this->team = Team::factory()->create([
        'getodoo_plan_id' => $plan->id,
        'show_boarding' => true,
    ]);
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner->id, [
        'role' => 'owner',
        'can_add_servers' => true,
    ]);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);
});

it('shows the platform name from configuration on the welcome screen', function () {
    instanceSettings()->update(['instance_name' => 'Nube Norte']);

    expect(product_name())->toBe('Nube Norte');

    Livewire::test(Index::class)
        ->assertSee('Welcome to Nube Norte')
        ->assertDontSee('Welcome to getcloud.sh')
        ->assertDontSee('Welcome to getodoo.sh');
});

it('walks a new account from welcome to a project, an invite, and the server step', function () {
    Livewire::test(Index::class)
        ->assertSet('currentState', 'getodoo-welcome')
        ->assertSee('Continue to create your first project')
        ->assertSee('getodoo-fireworks')
        ->assertDontSee('What you can do')
        ->assertDontSee('animate-spin')
        ->call('startGetOdooProject')
        ->assertSet('currentState', 'getodoo-project')
        ->set('projectName', 'Acme')
        ->call('createGetOdooProject')
        ->assertSet('currentState', 'getodoo-invite')
        ->assertHasNoErrors()
        ->assertDontSee('animate-spin')
        ->assertSee('Step 3 of 4')
        ->call('createGetOdooProject')
        ->set('inviteName', 'Ana Lopez')
        ->set('inviteEmail', 'Ana@Example.com')
        ->set('inviteRole', 'admin')
        ->call('inviteGetOdooTeammate')
        ->assertSet('currentState', 'getodoo-invite')
        ->assertSee('Ana Lopez')
        ->set('inviteName', 'Luis Perez')
        ->set('inviteEmail', 'luis@example.com')
        ->set('inviteRole', 'member')
        ->call('inviteGetOdooTeammate')
        ->assertSee('Luis Perez')
        ->assertSee('Ana Lopez')
        ->call('continueToGetOdooServer')
        ->assertSet('currentState', 'getodoo-servers')
        ->assertSee('This finishes the assistant.')
        ->assertSee('Thank you for being part of the getcloud.sh team.')
        ->assertSee('Create a new server')
        ->assertDontSee('animate-spin');

    $project = Project::query()->where('team_id', $this->team->id)->where('name', 'Acme')->first();

    expect($project)->not->toBeNull()
        ->and(Project::query()->where('team_id', $this->team->id)->where('name', 'Acme')->count())->toBe(1)
        ->and($project->environments()->where('name', 'production')->exists())->toBeTrue();

    $invitee = User::whereEmail('ana@example.com')->first();

    expect($invitee)->not->toBeNull()
        ->and($invitee->name)->toBe('Ana Lopez')
        ->and($invitee->teams()->where('teams.id', $this->team->id)->first()->pivot->role)->toBe('admin')
        ->and((bool) $invitee->teams()->where('teams.id', $this->team->id)->first()->pivot->can_add_servers)->toBeFalse()
        ->and(TeamInvitation::query()->where('email', 'ana@example.com')->value('role'))->toBe('admin')
        ->and(User::whereEmail('luis@example.com')->first()->teams()->where('teams.id', $this->team->id)->first()->pivot->role)->toBe('member');
});

it('stays on the invite step when the person is incomplete', function () {
    Livewire::test(Index::class)
        ->set('currentState', 'getodoo-invite')
        ->set('inviteName', 'Ana')
        ->set('inviteEmail', '')
        ->call('continueToGetOdooServer')
        ->assertSet('currentState', 'getodoo-invite')
        ->assertHasErrors(['inviteEmail']);
});

it('skips the removed product explanation when that step is in the url', function () {
    Livewire::withQueryParams(['step' => 'getodoo-tour'])
        ->test(Index::class)
        ->assertSet('currentState', 'getodoo-project')
        ->assertDontSee('How :product works');
});
