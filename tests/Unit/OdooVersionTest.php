<?php

use App\Domain\Odoo\OdooVersion;

test('reads the official odoo tag and leaves postgres alone when the version changes', function () {
    $compose = <<<'YAML'
services:
  odoo:
    image: odoo:18
    volumes:
      - odoo-web-data:/var/lib/odoo
  postgresql:
    image: postgres:16-alpine
YAML;

    expect(OdooVersion::current($compose))->toBe('18');
    expect(OdooVersion::image($compose))->toBe('odoo:18');

    $updated = OdooVersion::apply($compose, '19');

    expect($updated)->toContain('image: odoo:19');
    expect($updated)->toContain('image: postgres:16-alpine');
    expect($updated)->not->toContain('odoo:18');
});

test('changes a quoted image and a registry image without rewriting the rest of the file', function () {
    $quoted = "services:\n  odoo:\n    image: 'odoo:17'\n";
    expect(OdooVersion::apply($quoted, '18'))->toBe("services:\n  odoo:\n    image: 'odoo:18'\n");

    $registry = "services:\n  odoo:\n    image: ghcr.io/example/odoo:18\n";
    expect(OdooVersion::apply($registry, '19'))->toContain('image: ghcr.io/example/odoo:19');
});

test('accepts a numeric odoo tag and refuses a pinned digest, a word, and a non-odoo stack', function () {
    $pinned = "services:\n  odoo:\n    image: odoo:18@sha256:abc\n";
    expect(OdooVersion::apply($pinned, '19'))->toBe($pinned);
    expect(OdooVersion::current($pinned))->toBeNull();

    $compose = "services:\n  odoo:\n    image: odoo:18\n";
    expect(OdooVersion::apply($compose, '20'))->toContain('image: odoo:20');
    expect(OdooVersion::apply($compose, '21'))->toContain('image: odoo:21');
    expect(OdooVersion::apply($compose, 'latest'))->toBe($compose);

    $other = "services:\n  web:\n    image: nginx:alpine\n";
    expect(OdooVersion::apply($other, '19'))->toBe($other);
});
