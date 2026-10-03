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
