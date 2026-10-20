<?php

use App\Helpers\SshMultiplexingHelper;

it('passes small ssh scripts through as a shell string', function () {
    $seen = null;
    $result = SshMultiplexingHelper::withProcessCommand('echo ok', function (string|array $command) use (&$seen) {
        $seen = $command;

        return 'ran';
    });

    expect($result)->toBe('ran')
        ->and($seen)->toBe('echo ok');
});

it('runs large ssh scripts via a bash temp file to avoid argument list too long', function () {
    $payload = str_repeat('A', 70_000);
    $seen = null;
    $tmpPath = null;

    $result = SshMultiplexingHelper::withProcessCommand($payload, function (string|array $command) use (&$seen, &$tmpPath) {
        $seen = $command;
        if (is_array($command) && isset($command[1])) {
            $tmpPath = $command[1];
            expect(is_file($tmpPath))->toBeTrue()
                ->and(file_get_contents($tmpPath))->toBe(str_repeat('A', 70_000));
        }

        return 'ran-large';
    });

    expect($result)->toBe('ran-large')
        ->and($seen)->toBeArray()
        ->and($seen[0])->toBe('/bin/sh')
        ->and($tmpPath)->not->toBeNull()
        ->and(is_file($tmpPath))->toBeFalse();
});
