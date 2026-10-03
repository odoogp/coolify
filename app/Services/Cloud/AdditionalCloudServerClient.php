<?php

namespace App\Services\Cloud;

use Illuminate\Http\Client\Response;
use RuntimeException;

abstract class AdditionalCloudServerClient
{
    abstract public function ping(): bool;

    /**
     * @return list<array{id: string, label: string}>
     */
    abstract public function regions(): array;

    /**
     * @return list<array{id: string, label: string}>
     */
    abstract public function plans(string $region): array;

    /**
     * @return list<array{id: string, label: string}>
     */
    abstract public function images(string $region): array;

    abstract public function create(CloudServerRequest $request): CloudServerResult;

    abstract public function delete(string $id, ?string $region = null): void;

    protected function throwIfFailed(Response $response, string $action): void
    {
        if ($response->successful()) {
            return;
        }

        $body = trim((string) $response->body());
        $body = mb_substr($body, 0, 300);

        throw new RuntimeException($action.' failed ('.$response->status().').'.($body !== '' ? ' '.$body : ''));
    }

    protected function usableIp(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        $ip = trim($ip);

        if ($ip === '' || in_array($ip, ['0.0.0.0', '::', '1.2.3.4'], true)) {
            return null;
        }

        return $ip;
    }

    /**
     * @param  list<array{id: string, label: string}>  $rows
     * @return list<array{id: string, label: string}>
     */
    protected function preferOperatingSystems(array $rows): array
    {
        usort($rows, function (array $left, array $right): int {
            $rank = $this->operatingSystemRank($left['label']) <=> $this->operatingSystemRank($right['label']);

            return $rank !== 0 ? $rank : strnatcasecmp($right['label'], $left['label']);
        });

        return array_values(array_slice($rows, 0, 40));
    }

    protected function operatingSystemRank(string $label): int
    {
        $label = strtolower($label);

        foreach ([
            'ubuntu' => 0,
            'debian' => 1,
            'fedora' => 2,
            'almalinux' => 3,
            'alma' => 3,
            'rocky' => 4,
        ] as $name => $rank) {
            if (str_contains($label, $name)) {
                return $rank;
            }
        }

        return 9;
    }

    protected function userForImage(string $label): string
    {
        $label = strtolower($label);

        return match (true) {
            str_contains($label, 'ubuntu') => 'ubuntu',
            str_contains($label, 'debian') => 'debian',
            str_contains($label, 'fedora') => 'fedora',
            str_contains($label, 'alpine') => 'alpine',
            default => 'root',
        };
    }
}
