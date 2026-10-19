<?php

namespace App\Services\GetOdoo;

use App\Models\GetOdooPlanSignup;
use App\Models\GetOdooServerOrder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class N1coClient
{
    public function configured(): bool
    {
        return filled(instanceSettings()->n1co_secret) && filled(instanceSettings()->n1co_webhook_secret);
    }

    public function canChargePlans(): bool
    {
        return filled(instanceSettings()->n1co_secret);
    }

    public function charges(): bool
    {
        return $this->configured() && (bool) instanceSettings()->n1co_charges;
    }

    public function sandbox(): bool
    {
        return (bool) instanceSettings()->n1co_sandbox;
    }

    /**
     * @return array{id: string, url: string}
     */
    public function createServerLink(GetOdooServerOrder $order): array
    {
        $order->loadMissing('offer');
        $offer = $order->offer;
        $name = trim((string) ($offer?->description ?: $offer?->name ?: product_name()));

        return $this->checkout(
            $order->uuid,
            (float) $order->amount,
            product_name().' '.$name,
            $name.' · '.$order->location.' · '.__('per month'),
            route('getodoo.n1co.return', ['order' => $order->uuid]),
            route('getodoo.n1co.return', ['order' => $order->uuid, 'cancelled' => 1]),
        );
    }

    /**
     * @return array{id: string, url: string}
     */
    public function createPlanLink(GetOdooPlanSignup $signup): array
    {
        $signup->loadMissing('plan');
        $plan = $signup->plan;

        if ($plan === null) {
            throw new RuntimeException('This signup has no plan.');
        }

        $name = trim((string) ($plan->name ?: product_name()));

        return $this->checkout(
            $signup->uuid,
            (float) $signup->amount,
            product_name().' '.$name,
            trim((string) ($plan->summary ?: $name)),
            route('getodoo.plan.return', ['plan' => $plan->uuid, 'signup' => $signup->uuid]),
            $plan->publicUrl(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function order(string $orderCode): array
    {
        $payload = $this->request()
            ->get($this->baseUrl().'/paymentlink/order/'.rawurlencode($orderCode))
            ->throw()
            ->json();

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param  array<string, mixed>  $order
     */
    public function isPaid(array $order, mixed $amount): bool
    {
        $status = strtoupper((string) data_get($order, 'orderStatus'));
        $paid = $status === 'PAID' || ($status === 'FINALIZED' && filled(data_get($order, 'payment.authorizationCode')));

        return $paid && $this->sameMoney(data_get($order, 'total'), $amount);
    }

    public function webhookHash(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, $this->webhookSecret(), true));
    }

    public function hashMatches(string $expected, string $given): bool
    {
        $given = trim($given);

        return $given !== '' && hash_equals($expected, $given);
    }

    public function sameMoney(mixed $paid, mixed $expected): bool
    {
        return number_format((float) $paid, 2, '.', '') === number_format((float) $expected, 2, '.', '');
    }

    /**
     * @return array{id: string, url: string}
     */
    private function checkout(string $reference, float $amount, string $name, string $description, string $success, string $cancel): array
    {
        try {
            $response = $this->request()->post($this->baseUrl().'/paymentlink/checkout', [
                'orderReference' => $reference,
                'orderName' => mb_substr($name, 0, 255),
                'orderDescription' => mb_substr($description, 0, 2048),
                'amount' => round($amount, 2),
                'successUrl' => $success,
                'cancelUrl' => $cancel,
                'metadata' => [
                    ['name' => 'reference', 'value' => $reference],
                ],
            ])->throw();
        } catch (RequestException $exception) {
            throw new RuntimeException('n1co did not accept the secret key.', 0, $exception);
        }

        $url = $response->json('paymentLinkUrl');
        $code = $response->json('orderCode');

        if (! is_string($url) || $url === '' || ! is_string($code) || $code === '') {
            throw new RuntimeException('n1co did not return a payment link.');
        }

        return [
            'id' => $code,
            'url' => $url,
        ];
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->withToken($this->secret())
            ->timeout(20);
    }

    public function baseUrl(): string
    {
        return $this->sandbox()
            ? 'https://api-pay-sandbox.n1co.shop/api'
            : 'https://api-pay.n1co.shop/api';
    }

    private function secret(): string
    {
        $secret = instanceSettings()->n1co_secret;

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('n1co is not configured.');
        }

        return $secret;
    }

    private function webhookSecret(): string
    {
        $secret = instanceSettings()->n1co_webhook_secret;

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('n1co is not configured.');
        }

        return $secret;
    }
}
