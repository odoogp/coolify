<?php

use App\Livewire\Team\Invitations;
use App\Livewire\Team\InviteLink;
use App\Models\GetOdooPlan;
use App\Models\InstanceSettings;
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

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);
});

function usePlanTeamAsCurrent(Team $team, GetOdooPlan $plan, User $user): Team
{
    $team->forceFill(['getodoo_plan_id' => $plan->id])->save();
    $fresh = $team->fresh();
    Cache::forget('user:'.$user->id.':team:'.$team->id);
    $user->unsetRelation('teams');
    session(['currentTeam' => $fresh]);

    return $fresh;
}

it('shows generate link with loading state targeting viaLink', function () {
    $view = file_get_contents(resource_path('views/livewire/team/invite-link.blade.php'));

    expect($view)
        ->toContain('wire:submit="viaLink"')
        ->toContain('wire:target="viaLink"')
        ->toContain('<x-forms.button type="submit" wire:target="viaLink"')
        ->toContain('Generate link');

    Livewire::test(InviteLink::class)
        ->assertSee('Generate link')
        ->assertSeeHtml('wire:target="viaLink"')
        ->assertSeeHtml('wire:loading.attr="disabled"')
        ->assertSeeHtml('wire:loading.class="is-loading"');
});

it('does not offer owner invites on a getodoo plan organization team', function () {
    $plan = GetOdooPlan::query()->create([
        'name' => 'Plan org',
        'price' => 0,
        'currency' => 'USD',
        'is_active' => true,
        'is_rest_of_world' => true,
    ]);
    usePlanTeamAsCurrent($this->team, $plan, $this->owner);

    $view = file_get_contents(resource_path('views/livewire/team/invite-link.blade.php'));
    expect($view)->toContain('isGetOdooPlanTeam()');

    Livewire::test(InviteLink::class)
        ->set('email', 'invitee@example.com')
        ->set('role', 'owner')
        ->call('viaLink')
        ->assertDispatched('error');

    expect(TeamInvitation::query()->where('email', 'invitee@example.com')->exists())->toBeFalse();
});

it('hides membership limit forms from a plan principal and ignores server flags on invite', function () {
    $plan = GetOdooPlan::query()->create([
        'name' => 'Plan org limits',
        'price' => 0,
        'currency' => 'USD',
        'is_active' => true,
        'is_rest_of_world' => true,
        'can_add_servers' => true,
    ]);
    $team = usePlanTeamAsCurrent($this->team, $plan, $this->owner);

    expect(auth()->user()->can('updateCreationLimits', $team))->toBeFalse();

    Livewire::test(InviteLink::class)
        ->set('role', 'admin')
        ->assertDontSee(__('Production branches'))
        ->assertDontSee(__('Can launch instances on the server where GPSH is installed'))
        ->assertDontSee(__('Can add servers'))
        ->set('email', 'plan-admin@example.com')
        ->set('canAddServers', true)
        ->set('canLaunchOnInstanceServer', true)
        ->set('maxStagingBranches', 3)
        ->call('viaLink')
        ->assertDispatched('success');

    $invitation = TeamInvitation::query()->where('email', 'plan-admin@example.com')->first();
    expect($invitation)->not->toBeNull()
        ->and($invitation->role)->toBe('admin')
        ->and((bool) data_get($invitation->membership, 'can_add_servers'))->toBeFalse()
        ->and((bool) data_get($invitation->membership, 'can_launch_on_instance_server'))->toBeFalse()
        ->and(data_get($invitation->membership, 'max_staging_branches'))->toBeNull();
});

it('lets the instance owner assign membership limits on a plan organization', function () {
    $plan = GetOdooPlan::query()->create([
        'name' => 'Plan org instance owner',
        'price' => 0,
        'currency' => 'USD',
        'is_active' => true,
        'is_rest_of_world' => true,
    ]);
    $team = usePlanTeamAsCurrent($this->team, $plan, $this->owner);
    Team::find(0) ?? Team::factory()->create(['id' => 0, 'name' => 'Root Team', 'personal_team' => false]);
    $this->owner->teams()->attach(0, ['role' => 'owner']);
    $this->owner->unsetRelation('teams');
    $this->actingAs($this->owner->fresh(['teams']));
    Cache::forget('user:'.$this->owner->id.':team:'.$team->id);
    session(['currentTeam' => $team->fresh()]);

    expect(auth()->user()->can('updateCreationLimits', $team->fresh()))->toBeTrue();

    Livewire::test(InviteLink::class)
        ->set('role', 'admin')
        ->assertSee(__('Production branches'))
        ->assertSee(__('Can launch instances on the server where GPSH is installed'))
        ->set('email', 'instance-granted@example.com')
        ->set('canAddServers', true)
        ->set('canLaunchOnInstanceServer', true)
        ->set('maxStagingBranches', 2)
        ->call('viaLink')
        ->assertDispatched('success');

    $invitation = TeamInvitation::query()->where('email', 'instance-granted@example.com')->first();
    expect($invitation)->not->toBeNull()
        ->and((bool) data_get($invitation->membership, 'can_add_servers'))->toBeTrue()
        ->and((bool) data_get($invitation->membership, 'can_launch_on_instance_server'))->toBeTrue()
        ->and((int) data_get($invitation->membership, 'max_staging_branches'))->toBe(2);
});

it('renders a real copy button for pending invitation links', function () {
    $invitation = TeamInvitation::create([
        'team_id' => $this->team->id,
        'uuid' => 'test-invitation-uuid-123456789012',
        'email' => 'invitee@example.com',
        'role' => 'member',
        'link' => 'http://example.test/invitations/test-invitation-uuid-123456789012',
        'via' => 'link',
    ]);

    $view = file_get_contents(resource_path('views/livewire/team/invitations.blade.php'));

    expect($view)
        ->toContain('aria-label="{{ __(\'Copy invitation link\') }}"')
        ->toContain('window.copyToClipboard(@js($invite->link))')
        ->toContain('class="button h-7! shrink-0 px-2!"');

    Livewire::test(Invitations::class, [
        'invitations' => TeamInvitation::ownedByCurrentTeam()->get(),
    ])
        ->assertSee($invitation->link)
        ->assertSeeHtml('aria-label="Copy invitation link"')
        ->assertSeeHtml('window.copyToClipboard(')
        ->assertSeeHtml('type="button"');
});

it('exposes a resilient global copyToClipboard helper', function () {
    $layout = file_get_contents(resource_path('views/layouts/base.blade.php'));

    expect($layout)
        ->toContain('async function copyToClipboard(text)')
        ->toContain('window.copyToClipboard = copyToClipboard')
        ->toContain('document.execCommand(\'copy\')')
        ->toContain('window.isSecureContext');
});

it('preserves a provisional user when revoking their invitation fails', function () {
    $provisionalUser = User::factory()->create([
        'email' => 'provisional@example.com',
        'email_verified_at' => null,
        'force_password_reset' => true,
    ]);
    $invitation = TeamInvitation::create([
        'team_id' => $this->team->id,
        'uuid' => 'failing-invitation-delete',
        'email' => $provisionalUser->email,
        'role' => 'member',
        'link' => 'http://example.test/invitations/failing-invitation-delete',
        'via' => 'link',
    ]);

    TeamInvitation::deleting(function (): void {
        throw new RuntimeException('Invitation deletion failed.');
    });

    Livewire::test(Invitations::class, [
        'invitations' => collect([$invitation]),
    ])
        ->call('deleteInvitation', $invitation->id)
        ->assertDispatched('error');

    $this->assertDatabaseHas('users', ['id' => $provisionalUser->id]);
    $this->assertDatabaseHas('team_invitations', ['id' => $invitation->id]);
});
