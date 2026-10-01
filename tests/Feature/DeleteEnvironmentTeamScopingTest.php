<?php

use App\Jobs\DeleteResourceJob;
use App\Livewire\Project\DeleteEnvironment;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));

    // Current team
    $this->userA = User::factory()->create();
    $this->teamA = Team::factory()->create();
    $this->userA->teams()->attach($this->teamA, ['role' => 'owner']);
    $this->projectA = Project::factory()->create(['team_id' => $this->teamA->id]);
    $this->environmentA = Environment::factory()->create(['project_id' => $this->projectA->id]);

    // Another team
    $this->userB = User::factory()->create();
    $this->teamB = Team::factory()->create();
    $this->userB->teams()->attach($this->teamB, ['role' => 'owner']);
    $this->projectB = Project::factory()->create(['team_id' => $this->teamB->id]);
    $this->environmentB = Environment::factory()->create(['project_id' => $this->projectB->id]);

    $this->actingAs($this->userA);
    session(['currentTeam' => $this->teamA]);
});

test('mount cannot load DeleteEnvironment with environment from another team', function () {
    Livewire::test(DeleteEnvironment::class, ['environment_id' => $this->environmentB->id]);
})->throws(ModelNotFoundException::class);

test('mount can load DeleteEnvironment with own team environment', function () {
    $component = Livewire::test(DeleteEnvironment::class, ['environment_id' => $this->environmentA->id]);

    expect($component->get('environmentName'))->toBe($this->environmentA->name);
});

test('environment_id is locked and cannot be reassigned from the client', function () {
    $component = Livewire::test(DeleteEnvironment::class, ['environment_id' => $this->environmentA->id]);

    try {
        $component->set('environment_id', $this->environmentB->id);
        $this->fail('Setting a #[Locked] property should have thrown.');
    } catch (CannotUpdateLockedPropertyException) {
        expect(true)->toBeTrue();
    }
});

test('delete still removes an empty environment owned by the current team', function () {
    Livewire::test(DeleteEnvironment::class, ['environment_id' => $this->environmentA->id])
        ->call('delete', '')
        ->assertRedirect(route('project.show', ['project_uuid' => $this->projectA->uuid]));

    expect(Environment::find($this->environmentA->id))->toBeNull();
});

test('deleting an environment also deletes the resources it has', function () {
    Queue::fake();
    $application = Application::factory()->create([
        'environment_id' => $this->environmentA->id,
        'name' => 'odoo',
    ]);

    Livewire::test(DeleteEnvironment::class, ['environment_id' => $this->environmentA->id])
        ->assertSee('Delete the resources in this environment: odoo')
        ->call('delete', '')
        ->assertRedirect(route('project.show', ['project_uuid' => $this->projectA->uuid]));

    expect(Environment::find($this->environmentA->id))->toBeNull();
    expect(Application::withTrashed()->find($application->id)?->trashed())->toBeTrue();
    Queue::assertPushed(DeleteResourceJob::class, fn (DeleteResourceJob $job): bool => $job->resource->id === $application->id);
});

test('delete cannot resolve an environment from another team', function () {
    Application::factory()->create([
        'environment_id' => $this->environmentB->id,
    ]);

    $teamScopedLookup = fn () => Environment::ownedByCurrentTeam()
        ->findOrFail($this->environmentB->id);

    expect($teamScopedLookup)->toThrow(ModelNotFoundException::class);
});

test('team scoped lookup permits own team environment', function () {
    // Positive case so the cross-team check above cannot pass merely
    // because the helper itself is broken.
    $found = Environment::ownedByCurrentTeam()->findOrFail($this->environmentA->id);

    expect($found->id)->toBe($this->environmentA->id);
});
