<?php

namespace App\Services\GetOdoo;

use App\Models\GetOdooPricingArea;
use App\Models\Team;
use RuntimeException;

class GetOdooAreaEntitlements
{
    /**
     * @return array{allow_multiple_projects: bool, allow_all_services: bool, allowed_services: list<string>}
     */
    public static function open(): array
    {
        return [
            'allow_multiple_projects' => true,
            'allow_all_services' => true,
            'allowed_services' => [],
        ];
    }

    /**
     * Country entitlements only (regions are no longer used).
     *
     * @return array{allow_multiple_projects: bool, allow_all_services: bool, allowed_services: list<string>}
     */
    public static function resolve(?GetOdooPricingArea $country): array
    {
        if (! $country instanceof GetOdooPricingArea || ! $country->isCountry()) {
            return self::open();
        }

        return self::fromArea($country);
    }

    /**
     * @return array{allow_multiple_projects: bool, allow_all_services: bool, allowed_services: list<string>}
     */
    public static function forTeam(?Team $team): array
    {
        if (! $team instanceof Team || (int) $team->id === 0 || blank($team->getodoo_pricing_area_id)) {
            return self::open();
        }

        $area = GetOdooPricingArea::query()->find($team->getodoo_pricing_area_id);
        if (! $area instanceof GetOdooPricingArea || ! $area->isCountry()) {
            return self::open();
        }

        return self::fromArea($area);
    }

    public static function allowsService(?Team $team, ?string $serviceType): bool
    {
        if ($serviceType === null || $serviceType === '') {
            return true;
        }

        $entitlements = self::forTeam($team);
        if ($entitlements['allow_all_services']) {
            return true;
        }

        return in_array($serviceType, $entitlements['allowed_services'], true);
    }

    /**
     * @param  list<array{value: string, label: string, description?: ?string, category?: ?string, logo?: ?string}>  $options
     * @return list<array{value: string, label: string, description?: ?string, category?: ?string, logo?: ?string}>
     */
    public static function filterLaunchOptions(array $options, ?Team $team): array
    {
        $entitlements = self::forTeam($team);
        if ($entitlements['allow_all_services']) {
            return $options;
        }

        $allowed = $entitlements['allowed_services'];

        return array_values(array_filter(
            $options,
            fn (array $row): bool => in_array($row['value'], $allowed, true)
        ));
    }

    public static function assertServiceAllowed(?Team $team, ?string $serviceType): void
    {
        if (self::allowsService($team, $serviceType)) {
            return;
        }

        throw new RuntimeException(__('This service is not available for your country.'));
    }

    /**
     * @return array{allow_multiple_projects: bool, allow_all_services: bool, allowed_services: list<string>}
     */
    private static function fromArea(GetOdooPricingArea $area): array
    {
        if ($area->allow_all_services) {
            return [
                'allow_multiple_projects' => (bool) $area->allow_multiple_projects,
                'allow_all_services' => true,
                'allowed_services' => [],
            ];
        }

        return [
            'allow_multiple_projects' => (bool) $area->allow_multiple_projects,
            'allow_all_services' => false,
            'allowed_services' => self::normalizeServiceKeys($area->allowed_services),
        ];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    public static function normalizeServiceKeys(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn (mixed $key): string => strtolower(trim((string) $key)))
            ->filter(fn (string $key): bool => $key !== '' && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $key) === 1)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Cap a plan max_projects with area rules.
     */
    public static function capMaxProjects(?int $planMax, array $entitlements): ?int
    {
        if ($entitlements['allow_multiple_projects']) {
            return $planMax;
        }

        if ($planMax === null) {
            return 1;
        }

        return min(max(0, $planMax), 1);
    }
}
