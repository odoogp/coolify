<?php

namespace App\Services\GetOdoo;

use App\Models\GetOdooPlanSignup;
use App\Models\GetOdooServerOrder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

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
     * Codes n1co may use for the same link: the one we stored, the link slug, and the return query.
     *
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    public function candidateOrderCodes(?string $storedId, ?string $storedUrl, array $query = []): array
    {
        $codes = [];
        $push = function (?string $code) use (&$codes): void {
            $code = trim((string) $code);

            if ($code !== '' && ! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        };

        $push($storedId);
        $path = parse_url((string) $storedUrl, PHP_URL_PATH);
        $push(basename(is_string($path) ? $path : ''));

        foreach (['order_code', 'orderCode', 'OrderCode'] as $key) {
            $value = $query[$key] ?? null;

            if (is_string($value)) {
                $push($value);
            }
        }

        return $codes;
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, mixed>|null
     */
    public function paidOrder(array $codes, mixed $amount, ?string $reference = null): ?array
    {
        foreach ($codes as $code) {
            try {
                $order = $this->order($code);
            } catch (Throwable $exception) {
                report($exception);

                continue;
            }

            $remoteReference = $this->text($order, 'orderReference', 'OrderReference');

            if (is_string($reference) && $reference !== '' && $remoteReference !== '' && $remoteReference !== $reference) {
                continue;
            }

            if ($this->isPaid($order, $amount)) {
                return $order;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    public function isPaid(array $order, mixed $amount): bool
    {
        $order = $this->unwrap($order);

        if (! $this->hasPaidSignal($order)) {
            return false;
        }

        $paid = $this->moneyOf($order);

        return $paid !== null && $this->sameMoney($paid, $amount);
    }

    /**
     * @param  array<string, mixed>  $order
     */
    public function authorizationCode(array $order): string
    {
        $order = $this->unwrap($order);
        $direct = $this->text($order, 'authorizationCode', 'AuthorizationCode');

        if ($direct !== '') {
            return $direct;
        }

        foreach ($this->paymentRows($order) as $row) {
            $code = $this->text($row, 'authorizationCode', 'AuthorizationCode');

            if ($code !== '') {
                return $code;
            }
        }

        return $this->metadataValue($order, 'authorizationCode', 'AuthorizationCode', 'PaymentId');
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function unwrap(array $order): array
    {
        foreach (['data', 'result', 'order', 'Data', 'Result', 'Order'] as $key) {
            $nested = $order[$key] ?? null;

            if (! is_array($nested) || array_is_list($nested)) {
                continue;
            }

            if ($this->text($nested, 'orderStatus', 'OrderStatus', 'orderCode', 'OrderCode') !== '') {
                return $nested;
            }
        }

        return $order;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function hasPaidSignal(array $order): bool
    {
        $status = strtoupper($this->text($order, 'orderStatus', 'OrderStatus', 'status', 'Status'));

        if (in_array($status, ['CANCELLED', 'CANCELED', 'CANCELADO', 'PENDING', 'EXPIRED', 'FAILED', 'REJECTED', 'VOID'], true)) {
            return false;
        }

        $paymentStatuses = [];

        foreach ($this->paymentRows($order) as $row) {
            $paymentStatuses[] = strtoupper($this->text($row, 'status', 'Status', 'paymentStatus', 'PaymentStatus'));
        }

        $paymentStatuses[] = strtoupper($this->metadataValue($order, 'Status', 'status', 'paymentStatus'));

        foreach ($paymentStatuses as $paymentStatus) {
            if (in_array($paymentStatus, ['PAID', 'PAGADO', 'SUCCEEDED', 'SUCCESS', 'APPROVED', 'CAPTURED'], true)) {
                return true;
            }
        }

        if (in_array($status, ['PAID', 'PAGADO', 'SUCCEEDED', 'SUCCESS', 'APPROVED'], true)) {
            return true;
        }

        return $this->authorizationCode($order) !== '' && in_array($status, ['FINALIZED', 'FINALIZADO', 'COMPLETED', 'COMPLETE', '4'], true);
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function moneyOf(array $order): mixed
    {
        foreach (['total', 'Total', 'subtotal', 'Subtotal', 'amount', 'Amount'] as $key) {
            $candidate = $this->value($order, $key);

            if (is_numeric($candidate)) {
                return $candidate;
            }
        }

        foreach ($this->paymentRows($order) as $row) {
            foreach (['amount', 'total', 'Amount', 'Total'] as $key) {
                $candidate = $this->value($row, $key);

                if (is_numeric($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $order
     * @return list<array<string, mixed>>
     */
    private function paymentRows(array $order): array
    {
        $rows = [];

        foreach (['payment', 'charges', 'payments'] as $key) {
            $value = $this->value($order, $key);

            if (! is_array($value)) {
                continue;
            }

            if (array_is_list($value)) {
                foreach ($value as $row) {
                    if (is_array($row)) {
                        $rows[] = $row;
                    }
                }

                continue;
            }

            $rows[] = $value;
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $order
     */
    private function metadataValue(array $order, string ...$names): string
    {
        $metadata = $this->value($order, 'metadata', 'Metadata');

        if (! is_array($metadata)) {
            return '';
        }

        if (! array_is_list($metadata)) {
            foreach ($names as $name) {
                $direct = $this->text($metadata, $name);

                if ($direct !== '') {
                    return $direct;
                }
            }

            return '';
        }

        foreach ($metadata as $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = strtolower($this->text($row, 'name', 'Name'));

            foreach ($names as $wanted) {
                if ($name === strtolower($wanted)) {
                    return $this->text($row, 'value', 'Value');
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function text(array $source, string ...$keys): string
    {
        $value = $this->value($source, ...$keys);

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function value(array $source, string ...$keys): mixed
    {
        $lower = [];

        foreach ($source as $name => $item) {
            if (is_string($name)) {
                $lower[strtolower($name)] = $item;
            }
        }

        foreach ($keys as $key) {
            if (! array_key_exists(strtolower($key), $lower)) {
                continue;
            }

            $found = $lower[strtolower($key)];

            if ($found !== null && $found !== '') {
                return $found;
            }
        }

        return null;
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
