<?php

use App\Support\OdooZipBackup;

it('builds an odoo-format zip script with dump filestore and manifest', function () {
    $script = OdooZipBackup::createScript(
        pgContainer: 'postgresql-abc123',
        database: 'production',
        volume: 'abc123_odoo-web-data',
        workDir: '/data/coolify/backups/odoo/env/work-1',
        zipPath: '/data/coolify/backups/odoo/env/odoo-production-1.zip',
        version: '20',
    );

    expect($script)
        ->toContain('pg_dump')
        ->toContain('dump.sql')
        ->toContain('filestore')
        ->toContain('manifest.json')
        ->toContain('zip -qr')
        ->toContain('odoo_dump_version')
        ->toContain('production')
        ->toContain('20.0')
        ->toContain('abc123_odoo-web-data');
});

it('rejects unsafe database names in the zip script', function () {
    expect(fn () => OdooZipBackup::createScript(
        pgContainer: 'postgresql-abc123',
        database: 'prod; rm -rf /',
        volume: 'vol',
        workDir: '/tmp/w',
        zipPath: '/tmp/x.zip',
        version: '18',
    ))->toThrow(RuntimeException::class);
});

it('stores odoo zips under the backups odoo directory', function () {
    expect(OdooZipBackup::directory(null))->toEndWith('/backups/odoo/unknown');
});
