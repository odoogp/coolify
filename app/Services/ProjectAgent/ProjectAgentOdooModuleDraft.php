<?php

namespace App\Services\ProjectAgent;

use App\Models\Service;
use App\Support\OdooAddons;
use Illuminate\Support\Str;

class ProjectAgentOdooModuleDraft
{
    /**
     * @return array{technical_name: string, display_name: string, files: array<string, string>}
     */
    public static function scaffold(
        string $technicalName,
        string $displayName,
        string $modelName = 'item',
        string $odooVersion = '18.0',
    ): array {
        $technical = self::normalizeTechnicalName($technicalName);
        $model = self::normalizeTechnicalName($modelName);
        $modelClass = Str::studly($model);
        $modelXmlId = $technical.'.model_'.$technical.'_'.$model;
        $display = trim($displayName) !== '' ? trim($displayName) : Str::headline($technical);
        $displayPy = self::pyString($display);

        $files = [
            '__init__.py' => "from . import models\n",
            '__manifest__.py' => <<<PY
{
    'name': {$displayPy},
    'version': '{$odooVersion}.1.0.0',
    'category': 'Custom',
    'summary': {$displayPy},
    'depends': ['base'],
    'data': [
        'security/ir.model.access.csv',
        'views/{$model}_views.xml',
    ],
    'installable': True,
    'application': False,
    'license': 'LGPL-3',
}
PY,
            'models/__init__.py' => "from . import {$model}\n",
            "models/{$model}.py" => <<<PY
from odoo import fields, models


class {$modelClass}(models.Model):
    _name = '{$technical}.{$model}'
    _description = {$displayPy}

    name = fields.Char(required=True)
    active = fields.Boolean(default=True)
PY,
            "views/{$model}_views.xml" => <<<XML
<?xml version="1.0" encoding="utf-8"?>
<odoo>
    <record id="{$technical}_{$model}_list" model="ir.ui.view">
        <field name="name">{$technical}.{$model}.list</field>
        <field name="model">{$technical}.{$model}</field>
        <field name="arch" type="xml">
            <list>
                <field name="name"/>
                <field name="active"/>
            </list>
        </field>
    </record>

    <record id="{$technical}_{$model}_form" model="ir.ui.view">
        <field name="name">{$technical}.{$model}.form</field>
        <field name="model">{$technical}.{$model}</field>
        <field name="arch" type="xml">
            <form>
                <sheet>
                    <group>
                        <field name="name"/>
                        <field name="active"/>
                    </group>
                </sheet>
            </form>
        </field>
    </record>

    <record id="action_{$technical}_{$model}" model="ir.actions.act_window">
        <field name="name">{$display}</field>
        <field name="res_model">{$technical}.{$model}</field>
        <field name="view_mode">list,form</field>
    </record>

    <menuitem id="menu_{$technical}_{$model}"
              name="{$display}"
              action="action_{$technical}_{$model}"
              sequence="100"/>
</odoo>
XML,
            'security/ir.model.access.csv' => "id,name,model_id:id,group_id:id,perm_read,perm_write,perm_create,perm_unlink\naccess_{$technical}_{$model}_user,{$technical}.{$model}.user,{$modelXmlId},base.group_user,1,1,1,1\n",
        ];

        return [
            'technical_name' => $technical,
            'display_name' => $display,
            'files' => $files,
        ];
    }

    /**
     * @param  array<string, string>  $files
     * @return list<string>
     */
    public static function volumeWriteCommands(Service $service, string $technicalName, array $files): array
    {
        $technical = self::normalizeTechnicalName($technicalName);
        $volume = OdooAddons::extraAddonsVolume($service);
        $commands = [
            'docker volume create '.escapeshellarg($volume),
        ];

        $root = '/mnt/extra-addons/'.$technical;
        $scriptParts = [
            'mkdir -p '.$root.'/models '.$root.'/views '.$root.'/security',
        ];

        foreach ($files as $relative => $contents) {
            $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
            if ($relative === '' || str_contains($relative, '..') || ! preg_match('#^[a-zA-Z0-9_./-]+$#', $relative)) {
                continue;
            }
            $dir = dirname($relative);
            if ($dir !== '.') {
                $scriptParts[] = 'mkdir -p '.$root.'/'.$dir;
            }
            $target = $root.'/'.$relative;
            $b64 = base64_encode((string) $contents);
            $scriptParts[] = 'printf %s '.escapeshellarg($b64).' | base64 -d > '.$target;
        }

        $script = implode(' && ', $scriptParts);
        $commands[] = 'docker run --rm -v '.escapeshellarg($volume).':/mnt/extra-addons alpine:3.20 sh -c '.escapeshellarg($script);

        return $commands;
    }

    /**
     * @param  array{technical_name: string, display_name: string, files: array<string, string>}  $draft
     */
    public static function toMarkdown(array $draft): string
    {
        $lines = [
            '# Odoo module: '.$draft['display_name'],
            '',
            'Technical name: `'.$draft['technical_name'].'`',
            '',
            'Review these files before installing the module in Apps.',
            '',
        ];
        foreach ($draft['files'] as $path => $content) {
            $ext = pathinfo((string) $path, PATHINFO_EXTENSION);
            $lang = match ($ext) {
                'py' => 'python',
                'xml' => 'xml',
                'csv' => 'csv',
                default => '',
            };
            $lines[] = '## `'.$draft['technical_name'].'/'.$path.'`';
            $lines[] = '';
            $lines[] = '```'.$lang;
            $lines[] = rtrim((string) $content);
            $lines[] = '```';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    public static function normalizeTechnicalName(string $value): string
    {
        $slug = Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->trim('_')
            ->toString();

        if ($slug === '' || ! preg_match('/^[a-z][a-z0-9_]*$/', $slug)) {
            throw new \InvalidArgumentException('Module technical name must start with a letter and use only lowercase letters, numbers, and underscores.');
        }

        return $slug;
    }

    private static function pyString(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
