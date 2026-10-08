<?php

use App\Exceptions\AdminCreationQuotaExceeded;
use App\Http\Middleware\VerifyCsrfToken;
use App\Livewire\Project\AddEmpty;
use App\Livewire\Project\Show as ProjectShow;
use App\Livewire\Team\Create;
use App\Livewire\Team\InviteLink;
use App\Livewire\Team\Member as TeamMember;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\AdminCreationQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::query()->whereKey(0)->delete();
    $settings = new InstanceSettings(['is_api_enabled' => true]);
    $settings->id = 0;
    $settings->save();

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();

    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);
    $this->team->members()->attach($this->member->id, ['role' => 'member']);
});

function setAdminCreationQuota(?int $projects = null, ?int $environments = null, ?int $members = null): void
{
    test()->admin->teams()->updateExistingPivot(test()->team->id, [
        'max_projects' => $projects,
        'max_environments' => $environments,
        'max_members' => $members,
    ]);
}

test('null quotas do not limit an admin', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $project = app(AdminCreationQuota::class)->createProject($this->admin, [
        'name' => 'Open quota',
        'team_id' => $this->team->id,
    ]);

    expect($project->created_by)->toBe($this->admin->id)
        ->and($project->environments()->first()->created_by)->toBe($this->admin->id);
});

test('a zero project quota blocks creation and the included production does not need an environment slot', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    setAdminCreationQuota(projects: 0, environments: 5);

    expect(fn () => app(AdminCreationQuota::class)->createProject($this->admin, [
        'name' => 'Needs production',
        'team_id' => $this->team->id,
    ]))->toThrow(AdminCreationQuotaExceeded::class, 'To create more proyectos, contact an advisor to upgrade your plan.');

    expect(Project::query()->where('team_id', $this->team->id)->count())->toBe(0);

    setAdminCreationQuota(projects: 1, environments: 0);

    $project = app(AdminCreationQuota::class)->createProject($this->admin, [
        'name' => 'Included production',
        'team_id' => $this->team->id,
    ]);

    expect($project->environments()->pluck('name')->all())->toBe(['production']);
});

test('reaching the project quota blocks the next create and deleting frees the slot', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    setAdminCreationQuota(projects: 1, environments: 5);

    $first = app(AdminCreationQuota::class)->createProject($this->admin, [
        'name' => 'Only one',
        'team_id' => $this->team->id,
    ]);

    expect(fn () => app(AdminCreationQuota::class)->createProject($this->admin, [
        'name' => 'Second',
        'team_id' => $this->team->id,
    ]))->toThrow(AdminCreationQuotaExceeded::class, 'To create more proyectos, contact an advisor to upgrade your plan.');

    $first->delete();

    $second = app(AdminCreationQuota::class)->createProject($this->admin, [
        'name' => 'After delete',
        'team_id' => $this->team->id,
    ]);

    expect($second->created_by)->toBe($this->admin->id)
        ->and(Project::query()->where('created_by', $this->admin->id)->count())->toBe(1);
});

test('the project cap blocks another project even when environment slots remain', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    setAdminCreationQuota(projects: 1, environments: 5);

    app(AdminCreationQuota::class)->createProject($this->admin, [
        'name' => 'With production',
        'team_id' => $this->team->id,
    ]);

    expect(fn () => Project::create([
        'name' => 'Direct create',
        'team_id' => $this->team->id,
        'created_by' => $this->admin->id,
    ]))->toThrow(AdminCreationQuotaExceeded::class, 'To create more proyectos, contact an advisor to upgrade your plan.');

    expect(Project::query()->where('team_id', $this->team->id)->count())->toBe(1);
});

test('the included staging does not spend an environment slot and an extra environment does', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    setAdminCreationQuota(projects: 1, environments: 0);

    $project = app(AdminCreationQuota::class)->createProject($this->admin, [
        'name' => 'Clone target',
        'team_id' => $this->team->id,
    ], [
        ['name' => 'staging'],
    ]);

    expect($project->environments()->pluck('name')->sort()->values()->all())->toBe(['production', 'staging']);

    expect(fn () => Environment::create([
        'name' => 'qa',
        'project_id' => $project->id,
        'created_by' => $this->admin->id,
    ]))->toThrow(AdminCreationQuotaExceeded::class, 'To create more entornos, contact an advisor to upgrade your plan.');
});

test('resources without an author do not count and the owner ignores a quota', function () {
    Project::create([
        'name' => 'Legacy project',
        'team_id' => $this->team->id,
    ]);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    setAdminCreationQuota(projects: 1, environments: 5);

    app(AdminCreationQuota::class)->createProject($this->admin, [
        'name' => 'Admin project',
        'team_id' => $this->team->id,
    ]);

    expect(Project::query()->where('team_id', $this->team->id)->count())->toBe(2);

    expect(fn () => app(AdminCreationQuota::class)->createProject($this->admin, [
        'name' => 'Over cap',
        'team_id' => $this->team->id,
    ]))->toThrow(AdminCreationQuotaExceeded::class);

    $this->owner->teams()->updateExistingPivot($this->team->id, [
        'max_projects' => 0,
        'max_environments' => 0,
    ]);
    $this->actingAs($this->owner);

    $owned = app(AdminCreationQuota::class)->createProject($this->owner, [
        'name' => 'Owner project',
        'team_id' => $this->team->id,
    ]);

    expect($owned->created_by)->toBe($this->owner->id);
});

test('a pending invitation reserves a member and accepting it does not add another', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    setAdminCreationQuota(members: 1);

    Livewire::test(InviteLink::class)
        ->set('email', 'invitee@example.com')
        ->set('role', 'member')
        ->call('viaLink')
        ->assertDispatched('success');

    Livewire::test(InviteLink::class)
        ->set('email', 'second@example.com')
        ->set('role', 'member')
        ->call('viaLink')
        ->assertDispatched('error');

    expect(TeamInvitation::query()->where('invited_by', $this->admin->id)->count())->toBe(1);

    $invitation = TeamInvitation::query()->where('email', 'invitee@example.com')->first();
    $invitee = User::query()->where('email', 'invitee@example.com')->first();
    $invitee->forceFill(['email_verified_at' => now(), 'force_password_reset' => false])->save();
    $invitee->teams()->update(['show_boarding' => false]);

    $this->actingAs($invitee);
    $this->withoutMiddleware(VerifyCsrfToken::class)
        ->post(route('team.invitation.accept', ['uuid' => $invitation->uuid]))
        ->assertRedirect(route('team.index'));

    expect(TeamInvitation::query()->count())->toBe(0);
    expect((int) DB::table('team_user')->where('team_id', $this->team->id)->where('user_id', $invitee->id)->value('added_by'))->toBe($this->admin->id);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(InviteLink::class)
        ->set('email', 'third@example.com')
        ->set('role', 'member')
        ->call('viaLink')
        ->assertDispatched('error');

    DB::table('team_user')->where('user_id', $invitee->id)->where('team_id', $this->team->id)->delete();

    Livewire::test(InviteLink::class)
        ->set('email', 'third@example.com')
        ->set('role', 'member')
        ->call('viaLink')
        ->assertDispatched('success');
});

test('revoking a pending invitation frees the member slot and expired invitations do not count', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    setAdminCreationQuota(members: 1);

    $invitation = app(AdminCreationQuota::class)->createInvitation($this->admin, [
        'team_id' => $this->team->id,
        'uuid' => new_public_id(32),
        'email' => 'pending@example.com',
        'role' => 'member',
        'link' => 'http://localhost/invitations/pending',
        'via' => 'link',
    ]);

    expect(fn () => app(AdminCreationQuota::class)->createInvitation($this->admin, [
        'team_id' => $this->team->id,
        'uuid' => new_public_id(32),
        'email' => 'blocked@example.com',
        'role' => 'member',
        'link' => 'http://localhost/invitations/blocked',
        'via' => 'link',
    ]))->toThrow(AdminCreationQuotaExceeded::class, 'To create more miembros, contact an advisor to upgrade your plan.');

    $invitation->delete();

    app(AdminCreationQuota::class)->createInvitation($this->admin, [
        'team_id' => $this->team->id,
        'uuid' => new_public_id(32),
        'email' => 'after-revoke@example.com',
        'role' => 'member',
        'link' => 'http://localhost/invitations/after',
        'via' => 'link',
    ])->delete();

    $expired = new TeamInvitation;
    $expired->forceFill([
        'team_id' => $this->team->id,
        'uuid' => new_public_id(32),
        'email' => 'expired@example.com',
        'role' => 'member',
        'link' => 'http://localhost/invitations/expired',
        'via' => 'link',
        'invited_by' => $this->admin->id,
        'created_at' => now()->subDays(config('constants.invitation.link.expiration_days') + 2),
        'updated_at' => now()->subDays(config('constants.invitation.link.expiration_days') + 2),
    ]);
    $expired->timestamps = false;
    $expired->save();

    $fresh = app(AdminCreationQuota::class)->createInvitation($this->admin, [
        'team_id' => $this->team->id,
        'uuid' => new_public_id(32),
        'email' => 'still-open@example.com',
        'role' => 'member',
        'link' => 'http://localhost/invitations/open',
        'via' => 'link',
    ]);

    expect($fresh->invited_by)->toBe($this->admin->id);
});

test('imports do not attribute projects to the signed-in admin', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    setAdminCreationQuota(projects: 0, environments: 0);

    $project = app(AdminCreationQuota::class)->withoutEnforcement(fn () => Project::create([
        'name' => 'Imported',
        'team_id' => $this->team->id,
    ]));

    expect($project->created_by)->toBeNull()
        ->and($project->environments()->first()->created_by)->toBeNull();
});

test('the owner can set an admin quota and an admin cannot', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire::test(TeamMember::class, ['member' => $this->admin])
        ->assertSee('Save limits')
        ->set('maxProjects', 2)
        ->set('maxEnvironments', '')
        ->set('maxMembers', 0)
        ->call('saveCreationLimits')
        ->assertHasNoErrors()
        ->assertDispatched('success');

    $pivot = DB::table('team_user')->where('user_id', $this->admin->id)->where('team_id', $this->team->id)->first();
    expect((int) $pivot->max_projects)->toBe(2);
    expect($pivot->max_environments)->toBeNull();
    expect((int) $pivot->max_members)->toBe(0);

    $this->actingAs($this->admin);

    Livewire::test(TeamMember::class, ['member' => $this->admin])
        ->assertDontSee('Save limits')
        ->set('maxProjects', 9)
        ->call('saveCreationLimits')
        ->assertDispatched('error');

    expect((int) DB::table('team_user')->where('user_id', $this->admin->id)->value('max_projects'))->toBe(2);
});

test('livewire and the api enforce project and environment quotas', function () {
    setAdminCreationQuota(projects: 1, environments: 1);
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    Livewire::test(AddEmpty::class)
        ->assertSee('Projects: 0/1')
        ->set('name', 'From livewire')
        ->set('description', 'Created from the form')
        ->call('submit')
        ->assertRedirect();

    Livewire::test(AddEmpty::class)
        ->set('name', 'Blocked project')
        ->set('description', 'Should not exist')
        ->call('submit')
        ->assertDispatched('error');

    $project = Project::query()->where('name', 'From livewire')->first();
    expect($project)->not->toBeNull()
        ->and($project->created_by)->toBe($this->admin->id);

    Livewire::test(ProjectShow::class, ['project_uuid' => $project->uuid])
        ->set('name', 'staging')
        ->call('submit')
        ->assertDispatched('error');

    $this->actingAs($this->member);
    Livewire::test(AddEmpty::class)
        ->set('name', 'Member project')
        ->set('description', 'Not allowed')
        ->call('submit')
        ->assertDispatched('error');

    expect(Project::query()->where('name', 'Member project')->exists())->toBeFalse();

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    $token = $this->admin->createToken('quota', ['*'])->plainTextToken;

    $this->withToken($token)
        ->postJson('/api/v1/projects', ['name' => 'Api project'])
        ->assertForbidden()
        ->assertJsonPath('message', 'To create more proyectos, contact an advisor to upgrade your plan.');

    $this->withToken($token)
        ->postJson('/api/v1/projects/'.$project->uuid.'/environments', ['name' => 'staging'])
        ->assertForbidden()
        ->assertJsonPath('message', 'To create more entornos, contact an advisor to upgrade your plan.');
});

test('two creates stop at the cap', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    setAdminCreationQuota(projects: 2, environments: 5);

    $quota = app(AdminCreationQuota::class);
    $quota->createProject($this->admin, ['name' => 'One', 'team_id' => $this->team->id]);
    $quota->createProject($this->admin, ['name' => 'Two', 'team_id' => $this->team->id]);

    expect(fn () => $quota->createProject($this->admin, [
        'name' => 'Three',
        'team_id' => $this->team->id,
    ]))->toThrow(AdminCreationQuotaExceeded::class);

    expect(Project::query()->where('created_by', $this->admin->id)->count())->toBe(2);
});

test('an invited admin stays on the assigned team and cannot create another', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    Livewire::test(InviteLink::class)
        ->set('email', 'client@example.com')
        ->set('role', 'admin')
        ->call('viaLink')
        ->assertDispatched('success');

    $invitee = User::query()->where('email', 'client@example.com')->first();

    expect($invitee->teams()->count())->toBe(1)
        ->and($invitee->teams()->first()->id)->toBe($this->team->id)
        ->and($invitee->teams()->first()->pivot->role)->toBe('admin')
        ->and($invitee->can('create', Team::class))->toBeFalse();

    $this->actingAs($invitee);

    Livewire::test(Create::class)
        ->set('name', 'Client team')
        ->call('submit')
        ->assertForbidden();
});

test('logging in does not create a personal team for an invited admin', function () {
    $invitee = User::withoutPersonalTeam(fn () => User::factory()->create([
        'email' => 'invited-login@example.com',
        'password' => bcrypt('password'),
    ]));

    TeamInvitation::create([
        'team_id' => $this->team->id,
        'uuid' => new_public_id(32),
        'email' => $invitee->email,
        'role' => 'admin',
        'link' => 'http://localhost/invitations/invited-login',
        'via' => 'email',
    ]);

    $this->post('/login', [
        'email' => $invitee->email,
        'password' => 'password',
    ]);

    expect($invitee->teams()->where('personal_team', true)->exists())->toBeFalse()
        ->and($invitee->teams()->where('teams.id', $this->team->id)->exists())->toBeTrue();
});

test('an admin cannot exceed production, staging, or service quotas', function () {
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);

    $project = Project::factory()->create([
        'team_id' => $this->team->id,
        'created_by' => $this->owner->id,
    ]);
    $production = Environment::factory()->create([
        'project_id' => $project->id,
        'name' => 'production',
        'created_by' => $this->owner->id,
    ]);
    $staging = Environment::factory()->create([
        'project_id' => $project->id,
        'name' => 'staging',
        'created_by' => $this->owner->id,
    ]);

    $this->admin->teams()->updateExistingPivot($this->team->id, [
        'max_production_branches' => 1,
        'max_staging_branches' => 1,
        'max_services' => 1,
    ]);

    $this->actingAs($this->admin);

    $application = [
        'destination_id' => null,
        'destination_type' => null,
    ];

    Application::factory()->create([
        ...$application,
        'environment_id' => $production->id,
    ]);
    Application::factory()->create([
        ...$application,
        'environment_id' => $staging->id,
    ]);

    expect(fn () => Application::factory()->create([
        ...$application,
        'environment_id' => $production->id,
    ]))->toThrow(AdminCreationQuotaExceeded::class, 'To create more ramas de producción, contact an advisor to upgrade your plan.');

    expect(fn () => Application::factory()->create([
        ...$application,
        'environment_id' => $staging->id,
    ]))->toThrow(AdminCreationQuotaExceeded::class, 'To create more ramas de staging, contact an advisor to upgrade your plan.');

    $key = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $key->id,
    ]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();

    Service::factory()->create([
        'environment_id' => $production->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    expect(fn () => Service::factory()->create([
        'environment_id' => $production->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]))->toThrow(AdminCreationQuotaExceeded::class, 'To create more servicios, contact an advisor to upgrade your plan.');
});

test('another admin can launch the included staging without spending environment slots', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
    setAdminCreationQuota(projects: 1, environments: 0);
    $this->admin->teams()->updateExistingPivot($this->team->id, [
        'max_staging_branches' => 0,
    ]);

    $project = app(AdminCreationQuota::class)->createProject($this->admin, [
        'name' => 'Shared',
        'team_id' => $this->team->id,
    ]);
    $project->enableOdoo('20');

    $other = User::factory()->create();
    $other->teams()->attach($this->team->id, [
        'role' => 'admin',
        'max_projects' => 0,
        'max_environments' => 0,
        'max_staging_branches' => 0,
        'max_services' => 0,
    ]);

    $this->actingAs($other);
    session(['currentTeam' => $this->team]);

    expect($project->fresh()->canCreateStagingEnvironment())->toBeTrue();

    $staging = $project->fresh()->createNextStagingEnvironment();

    expect($staging->name)->toBe('staging-1')
        ->and((int) $staging->created_by)->toBe($other->id);
});

test('an admin opens the terminal only on an instance they created', function () {
    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);

    $project = Project::factory()->create([
        'team_id' => $this->team->id,
        'created_by' => $this->admin->id,
    ]);
    $other = User::factory()->create();
    $other->teams()->attach($this->team->id, ['role' => 'admin']);

    $this->actingAs($other);
    session(['currentTeam' => $this->team]);

    expect($this->admin->canOpenTerminal($project))->toBeTrue()
        ->and($other->canOpenTerminal($project))->toBeFalse()
        ->and($this->member->canOpenTerminal($project))->toBeFalse()
        ->and($this->owner->canOpenTerminal($project))->toBeTrue();
});

test('a client admin and a member can open the odoo shell of their team', function () {
    $project = Project::factory()->create([
        'team_id' => $this->team->id,
        'created_by' => $this->owner->id,
    ]);
    $environment = $project->environments()->first()
        ?? Environment::factory()->create(['name' => 'production', 'project_id' => $project->id]);
    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'docker_compose_raw' => "services:\n  odoo:\n    image: odoo:20\n",
        'created_by' => $this->owner->id,
    ]);
    $server = new Server;
    $server->id = 0;

    $this->actingAs($this->member);
    session(['currentTeam' => $this->team]);

    expect($this->member->canOpenTerminal($service))->toBeTrue()
        ->and($this->member->canOpenTerminal($server))->toBeFalse()
        ->and($this->member->can('canAccessTerminal'))->toBeTrue()
        ->and($this->admin->canOpenTerminal($service))->toBeTrue()
        ->and($this->admin->canOpenTerminal($project))->toBeFalse();
});
