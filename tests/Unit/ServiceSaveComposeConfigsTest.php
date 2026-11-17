<?php

it('writes docker-compose over SSH with base64 instead of local tmp scp', function () {
    $source = file_get_contents(base_path('app/Models/Service.php'));
    $method = str($source)
        ->after('public function saveComposeConfigs()')
        ->before('public function parse(bool $isNew = false)');

    expect($method->value())
        ->toContain("base64_encode(\$this->docker_compose)")
        ->toContain('base64 -d | tee')
        ->toContain('docker-compose.yml')
        ->not->toContain('instant_scp')
        ->not->toContain("Storage::disk('local')->put")
        ->not->toContain('tmp/{$filename}');

    // Large Odoo compose (waiting page HTML) must not hit ARG_MAX via sh -c.
    expect(file_get_contents(base_path('app/Helpers/SshMultiplexingHelper.php')))
        ->toContain('function withProcessCommand')
        ->and(file_get_contents(base_path('bootstrap/helpers/remoteProcess.php')))
        ->toContain('withProcessCommand');
});
