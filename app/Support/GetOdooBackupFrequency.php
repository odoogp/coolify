<?php

namespace App\Support;

class GetOdooBackupFrequency
{
    public const NONE = 'none';

    public const HOURLY = 'hourly';

    public const EVERY_6H = 'every_6h';

    public const TWICE_DAILY = 'twice_daily';

    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            self::NONE,
            self::HOURLY,
            self::EVERY_6H,
            self::TWICE_DAILY,
            self::DAILY,
            self::WEEKLY,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function choices(bool $includeNone = true): array
    {
        $choices = [
            ['value' => self::HOURLY, 'label' => __('Every hour')],
            ['value' => self::EVERY_6H, 'label' => __('Every 6 hours')],
            ['value' => self::TWICE_DAILY, 'label' => __('Twice a day')],
            ['value' => self::DAILY, 'label' => __('Daily')],
            ['value' => self::WEEKLY, 'label' => __('Weekly')],
        ];

        if ($includeNone) {
            array_unshift($choices, ['value' => self::NONE, 'label' => __('No automatic backups')]);
        }

        return $choices;
    }

    /**
     * @return list<string>
     */
    public static function scheduleKeys(): array
    {
        return array_values(array_filter(
            self::keys(),
            fn (string $key): bool => $key !== self::NONE
        ));
    }

    public static function cron(string $key): ?string
    {
        return match ($key) {
            self::HOURLY => '0 * * * *',
            self::EVERY_6H => '0 */6 * * *',
            self::TWICE_DAILY => '0 2,14 * * *',
            self::DAILY => '0 3 * * *',
            self::WEEKLY => '0 3 * * 0',
            default => null,
        };
    }

    public static function label(string $key): string
    {
        foreach (self::choices() as $choice) {
            if ($choice['value'] === $key) {
                return $choice['label'];
            }
        }

        return $key;
    }
}
