<?php

use App\Livewire\Team\InviteLink;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(['id' => 0], ['fqdn' => null]));

    // Create a team with owner, admin, and member
    $this->team = Team::factory()->create();

    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();

    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);
    $this->team->members()->attach($this->member->id, ['role' => 'member']);
});

describe('privilege escalation prevention', function () {
    test('member cannot invite admin (SECURITY FIX)', function () {
        // Login as member
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);

        // Attempt to invite someone as admin
        Livewire::test(InviteLink::class)
            ->set('email', 'newadmin@example.com')
            ->set('role', 'admin')
            ->call('viaLink')
            ->assertDispatched('error');
    });

    test('member cannot invite owner (SECURITY FIX)', function () {
        // Login as member
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);

        // Attempt to invite someone as owner
        Livewire::test(InviteLink::class)
            ->set('email', 'newowner@example.com')
            ->set('role', 'owner')
            ->call('viaLink')
            ->assertDispatched('error');
    });

    test('admin cannot invite owner', function () {
        // Login as admin
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        // Attempt to invite someone as owner
        Livewire::test(InviteLink::class)
            ->set('email', 'newowner@example.com')
            ->set('role', 'owner')
            ->call('viaLink')
            ->assertDispatched('error');
    });

    test('admin can invite member', function () {
        // Login as admin
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        // Invite someone as member
        Livewire::test(InviteLink::class)
            ->set('email', 'newmember@example.com')
            ->set('role', 'member')
            ->call('viaLink')
            ->assertDispatched('success');

        // Verify invitation was created
        $this->assertDatabaseHas('team_invitations', [
            'email' => 'newmember@example.com',
            'role' => 'member',
            'team_id' => $this->team->id,
        ]);
    });

    test('admin can invite admin', function () {
        // Login as admin
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        // Invite someone as admin
        Livewire::test(InviteLink::class)
            ->set('email', 'newadmin@example.com')
            ->set('role', 'admin')
            ->call('viaLink')
            ->assertDispatched('success');

        // Verify invitation was created
        $this->assertDatabaseHas('team_invitations', [
            'email' => 'newadmin@example.com',
            'role' => 'admin',
            'team_id' => $this->team->id,
        ]);
    });

    test('owner can invite member', function () {
        // Login as owner
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        // Invite someone as member
        Livewire::test(InviteLink::class)
            ->set('email', 'newmember@example.com')
            ->set('role', 'member')
            ->call('viaLink')
            ->assertDispatched('success');

        // Verify invitation was created
        $this->assertDatabaseHas('team_invitations', [
            'email' => 'newmember@example.com',
            'role' => 'member',
            'team_id' => $this->team->id,
        ]);
    });

    test('owner can invite admin', function () {
        // Login as owner
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        // Invite someone as admin
        Livewire::test(InviteLink::class)
            ->set('email', 'newadmin@example.com')
            ->set('role', 'admin')
            ->call('viaLink')
            ->assertDispatched('success');

        // Verify invitation was created
        $this->assertDatabaseHas('team_invitations', [
            'email' => 'newadmin@example.com',
            'role' => 'admin',
            'team_id' => $this->team->id,
        ]);
    });

    test('owner can invite owner', function () {
        // Login as owner
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        // Invite someone as owner
        Livewire::test(InviteLink::class)
            ->set('email', 'newowner@example.com')
            ->set('role', 'owner')
            ->call('viaLink')
            ->assertDispatched('success');

        // Verify invitation was created
        $this->assertDatabaseHas('team_invitations', [
            'email' => 'newowner@example.com',
            'role' => 'owner',
            'team_id' => $this->team->id,
        ]);
    });

    test('new user invitation magic link uses instance fqdn when configured', function () {
        InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
            ['id' => 0],
            ['fqdn' => 'https://coolify.example.com']
        ));

        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        Livewire::test(InviteLink::class)
            ->set('email', 'fqdn-invitee@example.com')
            ->set('role', 'member')
            ->call('viaLink')
            ->assertDispatched('success');

        $invitation = TeamInvitation::whereEmail('fqdn-invitee@example.com')->firstOrFail();

        expect($invitation->link)->toStartWith('https://coolify.example.com/auth/link?token=');
    });

    test('new user invitation magic link falls back to route url when instance fqdn is not configured', function () {
        InstanceSettings::unguarded(fn () => InstanceSettings::query()->updateOrCreate(
            ['id' => 0],
            ['fqdn' => null]
        ));

        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        Livewire::test(InviteLink::class)
            ->set('email', 'fallback-invitee@example.com')
            ->set('role', 'member')
            ->call('viaLink')
            ->assertDispatched('success');

        $invitation = TeamInvitation::whereEmail('fallback-invitee@example.com')->firstOrFail();

        $expectedPrefix = route('auth.link', ['token' => '']);
        expect($invitation->link)->toStartWith($expectedPrefix);
    });

    test('member cannot bypass policy by calling viaEmail', function () {
        // Login as member
        $this->actingAs($this->member);
        session(['currentTeam' => $this->team]);

        // Attempt to invite someone as admin via email
        Livewire::test(InviteLink::class)
            ->set('email', 'newadmin@example.com')
            ->set('role', 'admin')
            ->call('viaEmail')
            ->assertDispatched('error');
    });

    test('owner assigns client permissions when the user is created', function () {
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        Livewire::test(InviteLink::class)
            ->set('email', 'client-admin@example.com')
            ->set('role', 'admin')
            ->set('maxStagingBranches', 2)
            ->set('canAddServers', true)
            ->set('canLaunchOnInstanceServer', false)
            ->call('viaLink')
            ->assertDispatched('success');

        $invited = User::whereEmail('client-admin@example.com')->firstOrFail();
        $pivot = $invited->teams()->where('teams.id', $this->team->id)->firstOrFail()->pivot;
        expect($pivot->role)->toBe('admin')
            ->and((int) $pivot->max_staging_branches)->toBe(2)
            ->and((bool) $pivot->can_add_servers)->toBeTrue()
            ->and((bool) $pivot->can_launch_on_instance_server)->toBeFalse();
    });

    test('a member is created with the limits chosen before the sign-in link', function () {
        $this->actingAs($this->owner);
        session(['currentTeam' => $this->team]);

        Livewire::test(InviteLink::class)
            ->set('email', 'client-member@example.com')
            ->set('role', 'member')
            ->set('maxProjects', 1)
            ->set('maxProductionBranches', 1)
            ->set('maxStagingBranches', 2)
            ->set('maxServices', 3)
            ->set('canAddServers', true)
            ->set('odooAbilities', ['odoo.staging.deploy'])
            ->call('viaLink')
            ->assertDispatched('success');

        $invited = User::whereEmail('client-member@example.com')->firstOrFail();
        $pivot = $invited->teams()->where('teams.id', $this->team->id)->firstOrFail()->pivot;
        expect($pivot->role)->toBe('member')
            ->and((int) $pivot->max_projects)->toBe(1)
            ->and((int) $pivot->max_production_branches)->toBe(1)
            ->and((int) $pivot->max_staging_branches)->toBe(2)
            ->and((int) $pivot->max_services)->toBe(3)
            ->and((bool) $pivot->can_add_servers)->toBeFalse()
            ->and($pivot->odoo_abilities)->toContain('odoo.staging.deploy');
    });

    test('an admin cannot grant server access while inviting', function () {
        $this->actingAs($this->admin);
        session(['currentTeam' => $this->team]);

        Livewire::test(InviteLink::class)
            ->set('email', 'limited-admin@example.com')
            ->set('role', 'admin')
            ->set('canAddServers', true)
            ->call('viaLink')
            ->assertDispatched('success');

        $invited = User::whereEmail('limited-admin@example.com')->firstOrFail();
        $pivot = $invited->teams()->where('teams.id', $this->team->id)->firstOrFail()->pivot;
        expect((bool) $pivot->can_add_servers)->toBeFalse();
    });
});
