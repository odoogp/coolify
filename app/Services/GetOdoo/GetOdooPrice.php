<?php

namespace App\Services\GetOdoo;

class GetOdooPrice
{
    public const IPV4_EUR = 1.0;

    public function __construct(
        public float $eurUsd = 1.1,
        public float $taxPercent = 19.0,
        public float $marginPercent = 20.0,
    ) {}

    public static function current(): self
    {
        $settings = instanceSettings();

        return new self(
            (float) ($settings->getodoo_eur_usd_rate ?? 1.1),
            (float) ($settings->getodoo_tax_percent ?? 19),
            (float) ($settings->getodoo_margin_percent ?? 20),
        );
    }

    public function costEur(float $netEur): float
    {
        return round(($netEur + self::IPV4_EUR) * (1 + ($this->taxPercent / 100)), 2);
    }

    public function costUsd(float $netEur): float
    {
        return round($this->costEur($netEur) * $this->eurUsd, 2);
    }

    public function suggestedUsd(float $netEur): float
    {
        return round($this->costUsd($netEur) * (1 + ($this->marginPercent / 100)), 2);
    }
}
