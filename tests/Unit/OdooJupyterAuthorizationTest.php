<?php

use App\Models\Service;
use App\Models\User;
use App\Policies\ServicePolicy;

function jupyterPolicyUser(int $teamId, string $role): User
{
    $teams = collect([
        (object) ['id' => $teamId, 'pivot' => (object) ['role' => $role]],
    ]);

    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getAttribute')->with('teams')->andReturn($teams);

    return $user;
}

function jupyterPolicyService(int $teamId): Service
{
    $service = Mockery::mock(Service::class)->makePartial();
    $service->shouldReceive('team')->andReturn((object) ['id' => $teamId]);

    return $service;
}

test('a team member can view their odoo service and cannot enable jupyter', function () {
    $policy = new ServicePolicy;
    $user = jupyterPolicyUser(4, 'member');
    $service = jupyterPolicyService(4);

    expect($policy->view($user, $service))->toBeTrue();
    expect($policy->update($user, $service))->toBeFalse();
});

test('a user cannot view or update jupyter on a service from another team', function () {
    $policy = new ServicePolicy;
    $user = jupyterPolicyUser(4, 'owner');
    $service = jupyterPolicyService(9);

    expect($policy->view($user, $service))->toBeFalse();
    expect($policy->update($user, $service))->toBeFalse();
});

test('an admin of the team can enable jupyter on that service', function () {
    $policy = new ServicePolicy;

    expect($policy->update(jupyterPolicyUser(4, 'admin'), jupyterPolicyService(4)))->toBeTrue();
    expect($policy->update(jupyterPolicyUser(4, 'owner'), jupyterPolicyService(4)))->toBeTrue();
});
