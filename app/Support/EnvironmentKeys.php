<?php

namespace App\Support;

use App\Models\Environment;
use App\Models\Service;
use Illuminate\Support\Collection;

/**
 * Login credentials for services in one environment (Claves).
 * Odoo admin first; other stacks reuse Service::extraFields() password/login tips.
 */
class EnvironmentKeys
{
    /**
     * @return list<array{
     *     service_uuid: string,
     *     service_name: string,
     *     kind: string,
     *     title: string,
     *     credentials: list<array{
     *         id: string,
     *         label: string,
     *         key: string,
     *         value: string,
     *         kind: 'login'|'password'|'token'|'text',
     *         editable: bool
     *     }>
     * }>
     */
    public static function forEnvironment(Environment $environment): array
    {
        $environment->loadMissing('services');
        $cards = [];

        foreach ($environment->services as $service) {
            if (! $service instanceof Service) {
                continue;
            }
            if ($service->supportsOdooJupyter()) {
                $cards[] = self::odooCard($service);

                continue;
            }
            $fromFields = self::fromExtraFields($service);
            if ($fromFields !== null) {
                $cards[] = $fromFields;
            }
        }

        return $cards;
    }

    /**
     * @return array{
     *     service_uuid: string,
     *     service_name: string,
     *     kind: string,
     *     title: string,
     *     credentials: list<array{id: string, label: string, key: string, value: string, kind: 'login'|'password'|'token'|'text', editable: bool}>
     * }
     */
    public static function odooCard(Service $service): array
    {
        $password = OdooGit::runtimeValue($service, 'ODOO_ADMIN_PASSWORD');

        return [
            'service_uuid' => (string) $service->uuid,
            'service_name' => (string) $service->name,
            'kind' => 'odoo',
            'title' => __('Odoo administrator'),
            'credentials' => [
                [
                    'id' => 'odoo-admin-login',
                    'label' => __('Login'),
                    'key' => 'admin',
                    'value' => 'admin',
                    'kind' => 'login',
                    'editable' => false,
                ],
                [
                    'id' => 'odoo-admin-password',
                    'label' => __('Password'),
                    'key' => 'ODOO_ADMIN_PASSWORD',
                    'value' => $password,
                    'kind' => 'password',
                    'editable' => true,
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     service_uuid: string,
     *     service_name: string,
     *     kind: string,
     *     title: string,
     *     credentials: list<array{id: string, label: string, key: string, value: string, kind: 'login'|'password'|'token'|'text', editable: bool}>
     * }|null
     */
    public static function fromExtraFields(Service $service): ?array
    {
        /** @var Collection<string, array<string, mixed>> $groups */
        $groups = $service->extraFields();
        if ($groups->isEmpty()) {
            return null;
        }

        $credentials = [];
        foreach ($groups as $groupName => $fields) {
            if (! is_array($fields)) {
                continue;
            }
            foreach ($fields as $fieldLabel => $field) {
                if (! is_array($field)) {
                    continue;
                }
                $key = (string) data_get($field, 'key', '');
                $value = (string) data_get($field, 'value', '');
                if ($key === '') {
                    continue;
                }
                $isPassword = (bool) data_get($field, 'isPassword', false);
                $kind = $isPassword
                    ? (str_contains(strtoupper($key), 'TOKEN') ? 'token' : 'password')
                    : (str_contains(strtolower($fieldLabel), 'user') || str_contains(strtolower($fieldLabel), 'login') || str_contains(strtolower($fieldLabel), 'email')
                        ? 'login'
                        : 'text');
                // Only surface login material on Claves — skip plain config like Database Name.
                if (! in_array($kind, ['login', 'password', 'token'], true) && ! $isPassword) {
                    continue;
                }
                $credentials[] = [
                    'id' => $service->uuid.'-'.$key,
                    'label' => (string) $fieldLabel,
                    'key' => $key,
                    'value' => $value,
                    'kind' => $isPassword ? ($kind === 'token' ? 'token' : 'password') : $kind,
                    'editable' => false,
                ];
            }
        }

        if ($credentials === []) {
            return null;
        }

        return [
            'service_uuid' => (string) $service->uuid,
            'service_name' => (string) $service->name,
            'kind' => 'service',
            'title' => (string) $service->name,
            'credentials' => $credentials,
        ];
    }
}
