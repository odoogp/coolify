<?php

use App\Support\ResourceOverview;

it('turns catalog service names into readable names', function (string $name, string $uuid, string $expected) {
    expect(ResourceOverview::readableName($name, $uuid))->toBe($expected);
})->with([
    'uuid suffix' => ['authentik-kfkwdapyzvxvqty3gz8r3yov', 'kfkwdapyzvxvqty3gz8r3yov', 'Authentik'],
    'dashes become spaces' => ['uptime-kuma-abc123def456ghi789jkl0', 'abc123def456ghi789jkl0', 'Uptime kuma'],
    'custom names stay' => ['odoo-addons-production', 'hwfugtlnojfnvefaentvxdze', 'odoo-addons-production'],
]);

it('maps container statuses to four simple states', function (string $status, string $state) {
    expect(ResourceOverview::state($status))->toBe($state);
})->with([
    ['running:healthy', ResourceOverview::RUNNING],
    ['running:unknown', ResourceOverview::RUNNING],
    ['running:unhealthy', ResourceOverview::ATTENTION],
    ['degraded:unhealthy', ResourceOverview::ATTENTION],
    ['starting:unhealthy', ResourceOverview::STARTING],
    ['restarting', ResourceOverview::STARTING],
    ['exited', ResourceOverview::STOPPED],
    ['exited:unhealthy', ResourceOverview::STOPPED],
    ['', ResourceOverview::STOPPED],
]);
