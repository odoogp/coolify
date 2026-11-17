<?php

namespace App\Domain\Odoo;

/**
 * Which containers a client may see, and which services share the Odoo certificate.
 */
class OdooContainers
{
    /**
     * Clients see the Odoo and PostgreSQL containers. The instance admin sees the rest.
     */
    public static function clientSeesLog(string $container): bool
    {
        $name = strtolower(ltrim($container, '/'));
        foreach (['jupyter', 'stdlib', 'cadvisor', 'prometheus', 'monitor', 'beszel'] as $hidden) {
            if (str_contains($name, $hidden)) {
                return false;
            }
        }

        return str_contains($name, 'odoo') || str_contains($name, 'postgres');
    }

    public static function isOdooContainerLog(string $container): bool
    {
        $name = strtolower(ltrim($container, '/'));

        return str_contains($name, 'odoo')
            && ! str_contains($name, 'jupyter')
            && ! str_contains($name, 'stdlib');
    }

    /**
     * Odoo, Jupyter, the owner Jupyter, and the monitor share the certificate Traefik issues for Odoo.
     */
    public static function usesSharedCertificate(string $serviceKey): bool
    {
        $name = strtolower($serviceKey);
        $name = preg_replace('/_\d+$/', '', $name) ?? $name;

        return in_array($name, ['odoo', 'jupyter', 'jupyterowner', 'monitor'], true);
    }
}
