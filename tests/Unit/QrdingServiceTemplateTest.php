<?php

it('includes a QRding one-click service template', function () {
    $compose = file_get_contents(__DIR__.'/../../templates/compose/qrding.yaml');

    expect($compose)
        ->toContain("image: 'ghcr.io/rishikanthc/qrding:latest'")
        ->toContain('SERVICE_URL_QRDING_3000')
        ->toContain('HOST=0.0.0.0')
        ->toContain('PORT=3000')
        ->toContain('ORIGIN=${SERVICE_URL_QRDING}')
        ->toContain('DATABASE_URL=file:/data/local.db')
        ->toContain('qrding-data:/data');

    foreach (['service-templates.json', 'service-templates-latest.json'] as $templateFile) {
        $templates = json_decode(
            file_get_contents(__DIR__."/../../templates/{$templateFile}"),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($templates)->toHaveKey('qrding');
        expect($templates['qrding']['port'] ?? null)->toBe('3000');
        expect($templates['qrding']['logo'] ?? null)->toBe('svgs/qrding.svg');
        expect($templates['qrding']['category'] ?? null)->toBe('productivity');

        $generatedCompose = base64_decode($templates['qrding']['compose'], strict: true);

        expect($generatedCompose)
            ->toContain('ghcr.io/rishikanthc/qrding:latest')
            ->toContain('qrding-data:/data');
    }
});

it('ships the QRding service icon from the public path used by the service picker', function () {
    expect(__DIR__.'/../../public/svgs/qrding.svg')->toBeFile();
});
