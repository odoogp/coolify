<?php

namespace App\Services\GetOdoo;

use App\Models\CloudProviderToken;
use App\Models\GetOdooServerOffer;
use App\Services\HetznerService;
use RuntimeException;
use Throwable;

class GetOdooServerCatalog
{
    public function ownerToken(): ?CloudProviderToken
    {
        $tokenId = instanceSettings()->getodoo_hetzner_token_id;

        if (! $tokenId) {
            return null;
        }

        return CloudProviderToken::query()
            ->where('team_id', 0)
            ->where('provider', 'hetzner')
            ->find($tokenId);
    }

    public function rememberToken(CloudProviderToken $token): void
    {
        if ($token->provider !== 'hetzner' || (int) $token->team_id !== 0) {
            throw new RuntimeException(__('Choose a Hetzner token. Sold servers are created in that account.'));
        }

        instanceSettings()->update([
            'getodoo_hetzner_token_id' => $token->id,
        ]);
    }

    public function sync(): int
    {
        $token = $this->ownerToken();

        if ($token === null) {
            throw new RuntimeException(__('Choose a Hetzner token. Sold servers are created in that account.'));
        }

        try {
            $hetzner = new HetznerService($token->token);
            $types = $hetzner->getServerTypes();
        } catch (Throwable $exception) {
            report($exception);

            $detail = $exception->getMessage();

            throw new RuntimeException(str_starts_with($detail, 'Hetzner API error:')
                ? $detail
                : __('The Hetzner connection could not be updated.'));
        }

        $seen = [];

        foreach ($types as $type) {
            $openLocations = $this->availableLocationNames($type);
            $locations = array_values(array_filter(
                $this->locations($type),
                fn (array $row): bool => in_array($row['location'], $openLocations, true),
            ));

            if ($locations === []) {
                continue;
            }

            $cheapest = $locations[0];

            foreach ($locations as $location) {
                if ($location['monthly'] < $cheapest['monthly']) {
                    $cheapest = $location;
                }
            }

            GetOdooServerOffer::query()->updateOrCreate(
                ['hetzner_type_id' => (int) $type['id']],
                [
                    'name' => (string) ($type['name'] ?? ''),
                    'description' => (string) ($type['description'] ?? $type['name'] ?? ''),
                    'cores' => (int) ($type['cores'] ?? 0),
                    'memory' => (float) ($type['memory'] ?? 0),
                    'disk' => (int) ($type['disk'] ?? 0),
                    'architecture' => isset($type['architecture']) ? (string) $type['architecture'] : null,
                    'monthly_price' => $cheapest['monthly'],
                    'location' => $cheapest['location'],
                    'locations' => $locations,
                    'in_stock' => true,
                    'synced_at' => now(),
                ],
            );

            $seen[] = (int) $type['id'];
        }

        GetOdooServerOffer::query()
            ->when($seen !== [], fn ($query) => $query->whereNotIn('hetzner_type_id', $seen))
            ->update([
                'in_stock' => false,
                'available_for_admins' => false,
            ]);

        return count($seen);
    }

    public function ubuntuImageId(HetznerService $hetzner, string $architecture): int
    {
        $images = $hetzner->getImages();

        foreach (['ubuntu-24.04', 'ubuntu-22.04', 'ubuntu-26.04'] as $name) {
            foreach ($images as $image) {
                if (($image['name'] ?? null) !== $name || ($image['architecture'] ?? null) !== $architecture) {
                    continue;
                }

                if (! HetznerService::imageIsOrderable($image)) {
                    continue;
                }

                return (int) $image['id'];
            }
        }

        throw new RuntimeException(__('Ubuntu is not available for this server.'));
    }

    /**
     * Location names where Hetzner can create this server type right now.
     *
     * @param  array<string, mixed>  $type
     * @return array<int, string>
     */
    private function availableLocationNames(array $type): array
    {
        $names = [];

        foreach ($type['locations'] ?? [] as $location) {
            if (! is_array($location) || ($location['available'] ?? false) !== true) {
                continue;
            }

            $unavailableAfter = data_get($location, 'deprecation.unavailable_after');

            if (is_string($unavailableAfter) && $unavailableAfter !== '') {
                $timestamp = strtotime($unavailableAfter);

                if ($timestamp !== false && $timestamp <= time()) {
                    continue;
                }
            }

            $name = $location['name'] ?? null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $type
     * @return array<int, array{location: string, monthly: float}>
     */
    private function locations(array $type): array
    {
        $locations = [];

        foreach ($type['prices'] ?? [] as $price) {
            $monthly = data_get($price, 'price_monthly.gross') ?? data_get($price, 'price_monthly.net');

            if ($monthly === null || $monthly === '') {
                continue;
            }

            $location = $price['location'] ?? null;
            $name = is_array($location) ? ($location['name'] ?? null) : $location;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $locations[] = [
                'location' => $name,
                'monthly' => round((float) $monthly, 4),
            ];
        }

        return $locations;
    }
}
