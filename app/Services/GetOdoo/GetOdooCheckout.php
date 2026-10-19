<?php

namespace App\Services\GetOdoo;

use App\Models\GetOdooPlanSignup;
use App\Models\GetOdooServerOrder;
use RuntimeException;

class GetOdooCheckout
{
    public function __construct(
        private WompiClient $wompi,
        private N1coClient $n1co,
    ) {}

    public function provider(): ?string
    {
        if ($this->n1co->charges()) {
            return 'n1co';
        }

        if ($this->wompi->configured()) {
            return 'wompi';
        }

        return null;
    }

    /**
     * @return array{id: string, url: string}
     */
    public function linkForServer(GetOdooServerOrder $order): array
    {
        return match ($this->provider()) {
            'n1co' => $this->n1co->createServerLink($order),
            'wompi' => $this->wompi->createServerLink($order),
            default => throw new RuntimeException('The payment gateway is not ready.'),
        };
    }

    /**
     * @return array{id: string, url: string}
     */
    public function linkForPlan(GetOdooPlanSignup $signup): array
    {
        return match ($this->provider()) {
            'n1co' => $this->n1co->createPlanLink($signup),
            'wompi' => $this->wompi->createPlanLink($signup),
            default => throw new RuntimeException('The payment gateway is not ready.'),
        };
    }

    public function sameMoney(mixed $paid, mixed $expected): bool
    {
        return number_format((float) $paid, 2, '.', '') === number_format((float) $expected, 2, '.', '');
    }
}
