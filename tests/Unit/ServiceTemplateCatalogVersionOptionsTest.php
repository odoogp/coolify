<?php

use App\Models\OdooComposeTemplate;
use App\Support\ServiceTemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists Odoo versions from odoo_compose_templates, not a hardcoded preset', function () {
    expect(ServiceTemplateCatalog::versionOptions('odoo'))->toBe([]);

    seedOdooComposeVersions(['21', '18']);

    $options = ServiceTemplateCatalog::versionOptions('odoo');

    expect(collect($options)->pluck('value')->all())->toBe(['18', '21'])
        ->and(OdooComposeTemplate::configuredVersions())->toBe(['18', '21'])
        ->and(OdooComposeTemplate::isConfiguredVersion('18'))->toBeTrue()
        ->and(OdooComposeTemplate::isConfiguredVersion('19'))->toBeFalse();
});

it('hides version pickers for catalog services without multiple versions', function () {
    expect(ServiceTemplateCatalog::versionOptions('n8n'))->toBe([])
        ->and(ServiceTemplateCatalog::versionOptions('demo-worker'))->toBe([]);
});
