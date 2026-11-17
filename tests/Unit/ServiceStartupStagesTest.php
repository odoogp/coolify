<?php

use App\Support\ServiceStartupStages;

it('marks prepare as running at the start', function () {
    $stages = ServiceStartupStages::fromOutput('');

    expect($stages[0]['key'])->toBe(ServiceStartupStages::PREPARE)
        ->and($stages[0]['status'])->toBe('running')
        ->and($stages[1]['status'])->toBe('pending')
        ->and($stages[5]['status'])->toBe('pending');
});

it('advances through compose echoes into health while waiting for containers', function () {
    $log = <<<'LOG'
Saved configuration files to /data/coolify/services/abc.
Pulling images.
Creating Docker network.
Starting service.
 Container postgresql Creating
 Container authentik-server Started
 Container postgresql Waiting
LOG;

    $stages = ServiceStartupStages::fromOutput($log);

    expect(collect($stages)->firstWhere('key', ServiceStartupStages::PREPARE)['status'])->toBe('success')
        ->and(collect($stages)->firstWhere('key', ServiceStartupStages::PULL)['status'])->toBe('success')
        ->and(collect($stages)->firstWhere('key', ServiceStartupStages::START)['status'])->toBe('success')
        ->and(collect($stages)->firstWhere('key', ServiceStartupStages::HEALTH)['status'])->toBe('running')
        ->and(collect($stages)->firstWhere('key', ServiceStartupStages::ONLINE)['status'])->toBe('pending');
});

it('marks every stage success when the service is online', function () {
    $log = "Saved configuration files to /tmp.\nStarting service.\n";

    $stages = ServiceStartupStages::fromOutput($log, exitCode: 0, serviceOnline: true);

    expect(collect($stages)->pluck('status')->unique()->all())->toBe(['success']);
});

it('marks the current stage as error when the process fails', function () {
    $log = "Saved configuration files to /tmp.\nStarting service.\n";

    $stages = ServiceStartupStages::fromOutput($log, exitCode: 1, failed: true);

    expect(collect($stages)->firstWhere('key', ServiceStartupStages::START)['status'])->toBe('error')
        ->and(collect($stages)->firstWhere('key', ServiceStartupStages::ONLINE)['status'])->toBe('pending');
});
