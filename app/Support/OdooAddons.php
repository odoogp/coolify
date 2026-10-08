<?php

namespace App\Support;

use App\Models\Service;

class OdooAddons
{
    public static function extraAddonsVolume(Service $service): string
    {
        return $service->uuid.'_odoo-extra-addons';
    }

    public static function filestoreVolume(Service $service): string
    {
        return $service->uuid.'_odoo-web-data';
    }

    /**
     * @return list<string>
     */
    public static function copyCommands(Service $service, string $sourceDir): array
    {
        $volume = escapeshellarg(self::extraAddonsVolume($service));
        $source = escapeshellarg($sourceDir);

        $script = 'cp -a /source/. /mnt/extra-addons/; for item in /mnt/extra-addons/* /mnt/extra-addons/.[!.]*; do [ -e "$item" ] || continue; base=$(basename "$item"); [ "$base" = ".gpsh" ] && continue; [ -L "$item" ] && continue; [ -e "/source/$base" ] && continue; rm -rf "$item"; done';

        return [
            'docker volume create '.$volume,
            'docker run --rm -v '.$source.':/source:ro -v '.$volume.':/mnt/extra-addons alpine sh -c '.escapeshellarg($script),
        ];
    }

    /**
     * Unpack a zip/tar already at /source/addons.zip on the host into the extra-addons volume.
     * Flat module tree, or one wrapper folder, both work.
     *
     * @return list<string>
     */
    public static function unpackArchiveCommands(Service $service, string $remoteDirWithAddonsZip): array
    {
        $volume = escapeshellarg(self::extraAddonsVolume($service));
        $dir = escapeshellarg($remoteDirWithAddonsZip);

        $script = 'set -e; apk add --no-cache unzip >/dev/null; mkdir -p /tmp/addons-in /mnt/extra-addons; '
            .'cd /tmp/addons-in; '
            .'(unzip -qo /source/addons.zip 2>/dev/null || tar -xzf /source/addons.zip 2>/dev/null || tar -xf /source/addons.zip); '
            .'if [ "$(find . -mindepth 1 -maxdepth 1 -type d | wc -l)" = "1" ] '
            .'&& ! ls ./*/__manifest__.py >/dev/null 2>&1 && ! ls ./*/__openerp__.py >/dev/null 2>&1; then '
            .'  inner=$(find . -mindepth 1 -maxdepth 1 -type d | head -1); cp -a "$inner"/. /mnt/extra-addons/; '
            .'else cp -a ./. /mnt/extra-addons/; fi';

        return [
            'docker volume create '.$volume,
            'docker run --rm -v '.$volume.':/mnt/extra-addons -v '.$dir.':/source:ro alpine sh -c '.escapeshellarg($script),
        ];
    }

    /**
     * @return array{source_volume: string, target_volume: string, read_only_source: true}
     */
    public static function dataClonePlan(Service $source, Service $target): array
    {
        return [
            'source_volume' => self::filestoreVolume($source),
            'target_volume' => self::filestoreVolume($target),
            'read_only_source' => true,
        ];
    }
}
