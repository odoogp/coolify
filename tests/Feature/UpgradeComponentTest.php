<?php

use App\Livewire\Upgrade;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('initializes latest version during mount from cached versions data', function () {
    config(['constants.coolify.version' => '4.0.0-beta.998']);
    InstanceSettings::forceCreate([
        'id' => 0,
        'new_version_available' => true,
    ]);

    Cache::shouldReceive('remember')
        ->once()
        ->with('coolify:versions:all', 3600, Mockery::type(Closure::class))
        ->andReturn([
            'coolify' => [
                'v4' => [
                    'version' => '4.0.0-beta.999',
                ],
            ],
        ]);

    Livewire::test(Upgrade::class)
        ->assertSet('currentVersion', '4.0.0-beta.998')
        ->assertSet('latestVersion', '4.0.0-beta.999')
        ->assertSet('isUpgradeAvailable', true)
        ->assertSee('4.0.0-beta.998')
        ->assertSee('4.0.0-beta.999');
});

it('lets an instance admin read the upgrade while a client team is selected', function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $root = Team::factory()->create(['id' => 0]);
    $client = Team::factory()->create();
    $admin = User::factory()->create();
    $admin->teams()->attach($root, ['role' => 'owner']);
    $admin->teams()->attach($client, ['role' => 'admin']);
    $member = User::factory()->create();
    $member->teams()->attach($client, ['role' => 'member']);
    session(['currentTeam' => $client]);

    expect(Livewire::actingAs($admin)->test(Upgrade::class)->instance()->canReadUpgrade())->toBeTrue()
        ->and(Livewire::actingAs($member)->test(Upgrade::class)->instance()->canReadUpgrade())->toBeFalse();

    expect(file_get_contents(base_path('scripts/upgrade-local.sh')))
        ->toContain('write_status "2" "Pulling ${BRANCH}"');
});

it('does not highlight the current upgrade stage with the warning yellow accent', function () {
    $progressView = file_get_contents(resource_path('views/components/upgrade-progress.blade.php'));

    expect($progressView)
        ->toContain('bg-neutral-100 text-neutral-900 dark:bg-white/[0.08] dark:text-fg')
        ->not->toContain('dark:text-warning')
        ->not->toContain('dark:bg-warning');
});

it('uses a current-color spinner on upgrade stages instead of the brand purple loader', function () {
    $progressView = file_get_contents(resource_path('views/components/upgrade-progress.blade.php'));
    $appCss = file_get_contents(resource_path('css/app.css'));

    expect($progressView)
        ->toContain('spinner-current')
        ->not->toContain('loading-indicator')
        ->and($appCss)
        ->toContain('.dark .animate-spin.spinner-current')
        ->toContain('html[data-theme="custom"] .animate-spin.spinner-current')
        ->toContain('color: inherit !important;');
});

it('treats a brief upgrade poll miss as a reconnect, not a lost-contact failure', function () {
    $upgradeView = file_get_contents(resource_path('views/livewire/upgrade.blade.php'));

    expect($upgradeView)
        ->toContain('Reconnecting. This is expected during an upgrade...')
        ->not->toContain('Lost contact with Coolify');
});

it('renders the upgrade control in the mobile top bar', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)
        ->toMatch('/MOBILE TOP BAR[\s\S]*?<livewire:upgrade[^>]*>[\s\S]*?Open sidebar/')
        ->toContain('key="mobile-upgrade"');
});

it('supports a full size update button for settings', function () {
    $upgradeView = file_get_contents(resource_path('views/livewire/upgrade.blade.php'));
    $settingsView = file_get_contents(resource_path('views/livewire/settings/updates.blade.php'));

    expect($upgradeView)
        ->toContain('$fullButton')
        ->toContain('Upgrade now')
        ->and($settingsView)
        ->toContain('Update Coolify')
        ->toContain(':full-button="true"')
        ->toContain('key="settings-upgrade"');
});

it('uses compact labels that do not depend on desktop sidebar state', function () {
    $upgradeView = file_get_contents(resource_path('views/livewire/upgrade.blade.php'));

    expect($upgradeView)
        ->toContain('Updating')
        ->toContain('Update available')
        ->not->toContain(':class="collapsed');
});

it('falls back to 0.0.0 during mount when cached versions data is unavailable', function () {
    InstanceSettings::forceCreate([
        'id' => 0,
        'new_version_available' => false,
    ]);

    Cache::shouldReceive('remember')
        ->once()
        ->with('coolify:versions:all', 3600, Mockery::type(Closure::class))
        ->andReturn(null);

    Livewire::test(Upgrade::class)
        ->assertSet('latestVersion', '0.0.0');
});

it('clears stale upgrade availability when current version already matches latest version', function () {
    config(['constants.coolify.version' => '4.0.0-beta.999']);
    InstanceSettings::forceCreate([
        'id' => 0,
        'new_version_available' => true,
    ]);

    Cache::shouldReceive('remember')
        ->once()
        ->with('coolify:versions:all', 3600, Mockery::type(Closure::class))
        ->andReturn([
            'coolify' => [
                'v4' => [
                    'version' => '4.0.0-beta.999',
                ],
            ],
        ]);

    Livewire::test(Upgrade::class)
        ->assertSet('latestVersion', '4.0.0-beta.999')
        ->assertSet('isUpgradeAvailable', false);

    expect((bool) InstanceSettings::findOrFail(0)->new_version_available)->toBeFalse();
});

it('clears stale upgrade availability when current version is newer than cached latest version', function () {
    config(['constants.coolify.version' => '4.0.0-beta.1000']);
    InstanceSettings::forceCreate([
        'id' => 0,
        'new_version_available' => true,
    ]);

    Cache::shouldReceive('remember')
        ->once()
        ->with('coolify:versions:all', 3600, Mockery::type(Closure::class))
        ->andReturn([
            'coolify' => [
                'v4' => [
                    'version' => '4.0.0-beta.999',
                ],
            ],
        ]);

    Livewire::test(Upgrade::class)
        ->assertSet('latestVersion', '4.0.0-beta.999')
        ->assertSet('isUpgradeAvailable', false);

    expect((bool) InstanceSettings::findOrFail(0)->new_version_available)->toBeFalse();
});

it('keeps the update steps hidden until they are opened', function () {
    config(['constants.coolify.version' => '4.0.0-beta.998']);
    InstanceSettings::forceCreate([
        'id' => 0,
        'new_version_available' => false,
    ]);
    Cache::shouldReceive('remember')
        ->once()
        ->with('coolify:versions:all', 3600, Mockery::type(Closure::class))
        ->andReturn(null);

    Livewire::test(Upgrade::class)
        ->assertSet('showUpdateSteps', false)
        ->set('updateInProgress', true)
        ->set('currentVersion', '4.0.0-beta.998')
        ->set('latestVersion', '4.0.0-beta.999')
        ->call('toggleUpdateSteps')
        ->assertSet('showUpdateSteps', true)
        ->assertSet('updateInProgress', true)
        ->assertSet('currentVersion', '4.0.0-beta.998')
        ->assertSet('latestVersion', '4.0.0-beta.999')
        ->call('toggleUpdateSteps')
        ->assertSet('showUpdateSteps', false)
        ->assertSet('updateInProgress', true)
        ->assertSet('currentVersion', '4.0.0-beta.998')
        ->assertSet('latestVersion', '4.0.0-beta.999');
});

it('hides the update steps without changing how the upgrade runs', function () {
    $upgradeView = file_get_contents(resource_path('views/livewire/upgrade.blade.php'));
    $progressView = file_get_contents(resource_path('views/components/upgrade-progress.blade.php'));

    expect($upgradeView)
        ->toContain('showUpdateSteps')
        ->toContain('x-show="showUpdateSteps"')
        ->toContain('x-text="currentStatus"')
        ->toContain("this.\$wire.\$call('upgrade')")
        ->toContain('this.$wire.getUpgradeStatus()')
        ->toContain('Update in progress...')
        ->toContain('Show steps')
        ->toContain('Hide steps')
        ->toContain('Show log')
        ->toContain('Hide log')
        ->toContain('showUpgradeLog')
        ->toContain('this.$wire.upgradeLog()')
        ->not->toContain('upgrade-*.log');

    expect($progressView)
        ->toContain('Preparing update')
        ->toContain('Fetching repository')
        ->toContain('Building image')
        ->toContain('Recreating Coolify')
        ->toContain('Health check');
});
