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

        if ($this->bankTransfer() !== null) {
            return 'transfer';
        }

        return null;
    }

    public function bankTransfer(): ?string
    {
        $text = trim((string) (instanceSettings()->bank_transfer_instructions ?? ''));

        return $text === '' ? null : $text;
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
     * Gateways that can charge a plan right now. Wompi and n1co can both be ready.
     *
     * @return list<string>
     */
    public function gateways(): array
    {
        $gateways = [];

        if ($this->wompi->configured()) {
            $gateways[] = 'wompi';
        }

        if ($this->n1co->canChargePlans()) {
            $gateways[] = 'n1co';
        }

        if ($gateways === [] && $this->bankTransfer() !== null) {
            $gateways[] = 'transfer';
        }

        return $gateways;
    }

    /**
     * @return array{id: string, url: string}
     */
    public function linkForPlan(GetOdooPlanSignup $signup, ?string $gateway = null): array
    {
        $gateway ??= $this->provider();

        return match ($gateway) {
            'n1co' => $this->n1co->canChargePlans()
                ? $this->n1co->createPlanLink($signup)
                : throw new RuntimeException('The payment gateway is not ready.'),
            'wompi' => $this->wompi->configured()
                ? $this->wompi->createPlanLink($signup)
                : throw new RuntimeException('The payment gateway is not ready.'),
            default => throw new RuntimeException('The payment gateway is not ready.'),
        };
    }

    public function sameMoney(mixed $paid, mixed $expected): bool
    {
        return number_format((float) $paid, 2, '.', '') === number_format((float) $expected, 2, '.', '');
    }
}
