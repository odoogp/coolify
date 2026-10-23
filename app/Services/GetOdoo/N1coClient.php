<?php

namespace App\Services\GetOdoo;

use App\Models\GetOdooPlan;
use App\Models\GetOdooPlanSignup;
use App\Models\GetOdooServerOrder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

        $label = product_name().' '.$name;
        $description = $name.' · '.$order->location.' · '.__('per month');
        $mode = $order->charge_mode ?: (instanceSettings()->n1co_server_charge_mode ?: GetOdooPlan::CHARGE_ONCE);

        if ($mode === GetOdooPlan::CHARGE_SUBSCRIPTION) {
            return $this->subscriptionLink($order->uuid, (float) $order->amount, $label, $description);
        }

        return $this->checkout(
            $order->uuid,
            (float) $order->amount,
            $label,
            $description,
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
        $mode = $signup->charge_mode ?: ($plan->charge_mode ?: GetOdooPlan::CHARGE_ONCE);

        $startContext = [
            'signup_uuid' => $signup->uuid,
            'plan_id' => $plan->id,
            'amount' => (float) $signup->amount,
            'charge_mode' => $mode,
            'sandbox' => $this->sandbox(),
            'checkout_base' => $this->baseUrl(),
            'subscriptions_base' => $this->subscriptionsBaseUrl(),
        ];
        $this->writeLog('n1co: createPlanLink starting', $startContext, 'info');

        try {
            if ($mode === GetOdooPlan::CHARGE_SUBSCRIPTION) {
                return $this->subscriptionLink(
                    $signup->uuid,
                    (float) $signup->amount,
                    product_name().' '.$name,
                    trim((string) ($plan->summary ?: $name)),
                );
            }

            return $this->checkout(
                $signup->uuid,
                (float) $signup->amount,
                product_name().' '.$name,
                trim((string) ($plan->summary ?: $name)),
                route('getodoo.plan.return', ['plan' => $plan->uuid, 'signup' => $signup->uuid]),
                $plan->publicUrl(),
            );
        } catch (ConnectionException $exception) {
            $this->logFailure('API connection timed out or unreachable', $exception, [
                'signup_uuid' => $signup->uuid,
                'charge_mode' => $mode,
                'checkout_base' => $this->baseUrl(),
            ]);

            throw new RuntimeException(__('n1co did not respond in time. Check sandbox credentials and that the server can reach n1co.'), 0, $exception);
        }
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
     * @return array<string, mixed>|null
     */
    public function paidSubscription(?string $planId, mixed $amount): ?array
    {
        if (! is_string($planId) || $planId === '') {
            return null;
        }

        try {
            $rows = $this->subscriptionRequest()
                ->get($this->subscriptionsBaseUrl().'/Plans/'.rawurlencode($planId).'/Subscriptions')
                ->throw()
                ->json();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $status = (string) ($row['subscriptionStatus'] ?? '');

            if (! in_array($status, ['Activa', 'Active'], true)) {
                continue;
            }

            if ($this->sameMoney($row['amount'] ?? null, $amount)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * @return array{id: string, url: string, plan_id: string, charge_mode: string}
     */
    private function subscriptionLink(string $reference, float $amount, string $name, string $description): array
    {
        $locationId = (int) instanceSettings()->n1co_location_id;

        if ($locationId < 1) {
            $this->logFailure('subscription branch id missing', null, [
                'reference' => $reference,
                'amount' => $amount,
            ]);

            throw new RuntimeException(__('n1co needs a branch id before a subscription can start.'));
        }

        $suffix = ' '.$reference;
        $label = mb_substr(trim($name), 0, max(1, 120 - mb_strlen($suffix))).$suffix;

        try {
            $response = $this->subscriptionRequest()->post($this->subscriptionsBaseUrl().'/Plans', [
                'name' => $label,
                'description' => mb_substr($description !== '' ? $description : $name, 0, 500),
                'amount' => round($amount, 2),
                'billingCycleType' => 'Month',
                'billingCyclesNumber' => 1,
                'cyclesToBillBeforeAllowCancelation' => null,
                'subscriberLimit' => 1,
                'enrollmentEndDate' => null,
                'subscriptionEndDate' => null,
                'billingDay' => null,
                'locationId' => $locationId,
            ])->throw();
        } catch (RequestException $exception) {
            $message = $this->httpErrorMessage($exception, __('n1co did not accept the subscription.'));
            $this->logFailure('subscription plan create failed', $exception, [
                'reference' => $reference,
                'amount' => $amount,
                'location_id' => $locationId,
            ]);

            throw new RuntimeException($message, 0, $exception);
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $paymentLink = is_array($payload['paymentLink'] ?? null) ? $payload['paymentLink'] : [];
        $planId = $payload['planId'] ?? $payload['plan_id'] ?? null;
        $url = $paymentLink['linkUrl'] ?? $paymentLink['link_url'] ?? $paymentLink['url'] ?? null;
        $code = $paymentLink['code'] ?? null;

        if (! is_numeric($planId) || ! is_string($url) || $url === '' || ! is_string($code) || $code === '') {
            $this->logFailure('subscription plan response missing link', null, [
                'reference' => $reference,
                'amount' => $amount,
                'response' => $this->safeBody($payload),
            ]);

            throw new RuntimeException(__('n1co did not return a subscription link.'));
        }

        return [
            'id' => $code,
            'url' => $url,
            'plan_id' => (string) $planId,
            'charge_mode' => GetOdooPlan::CHARGE_SUBSCRIPTION,
        ];
    }

    public function subscriptionsBaseUrl(): string
    {
        return $this->sandbox()
            ? 'https://api-sandbox.n1co.shop/api/v3'
            : 'https://api.n1co.shop/api/v3';
    }

    /**
     * @return array{id: string, url: string, plan_id: null, charge_mode: string}
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
            $message = $this->httpErrorMessage($exception, __('n1co did not accept the secret key.'));
            $this->logFailure('payment link checkout failed', $exception, [
                'reference' => $reference,
                'amount' => $amount,
            ]);

            throw new RuntimeException($message, 0, $exception);
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];
        $url = $payload['paymentLinkUrl'] ?? $payload['payment_link_url'] ?? $payload['url'] ?? null;
        $code = $payload['orderCode'] ?? $payload['order_code'] ?? $payload['code'] ?? null;

        if (! is_string($url) || $url === '' || ! is_string($code) || $code === '') {
            $this->logFailure('payment link response missing url/code', null, [
                'reference' => $reference,
                'amount' => $amount,
                'response' => $this->safeBody($payload),
            ]);

            throw new RuntimeException(__('n1co did not return a payment link.'));
        }

        return [
            'id' => $code,
            'url' => $url,
            'plan_id' => null,
            'charge_mode' => GetOdooPlan::CHARGE_ONCE,
        ];
    }

    private function request(): PendingRequest
    {
        // Short connect timeout so a blackholed sandbox host cannot leave Livewire spinning forever.
        return Http::acceptJson()
            ->withToken($this->secret())
            ->connectTimeout(5)
            ->timeout(15);
    }

    private function subscriptionRequest(): PendingRequest
    {
        return Http::acceptJson()
            ->withToken($this->accessToken())
            ->connectTimeout(5)
            ->timeout(15);
    }

    private function accessToken(): string
    {
        $settings = instanceSettings();
        $clientId = trim((string) ($settings->n1co_client_id ?? ''));
        $clientSecret = trim((string) ($settings->n1co_client_secret ?? ''));

        if ($clientId === '' || $clientSecret === '') {
            $this->logFailure('subscription credentials missing', null, [
                'has_client_id' => $clientId !== '',
                'has_client_secret' => $clientSecret !== '',
                'has_location_id' => (int) ($settings->n1co_location_id ?? 0) > 0,
            ]);

            throw new RuntimeException(__('n1co needs the API client id and the client secret for a monthly subscription.'));
        }

        $cacheKey = 'n1co-access-'.hash('sha256', $clientId.'|'.$clientSecret.'|'.($this->sandbox() ? 'sandbox' : 'live'));

        $token = Cache::get($cacheKey);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        try {
            $response = Http::acceptJson()
                ->timeout(20)
                ->post($this->subscriptionsBaseUrl().'/Token', [
                    'clientId' => $clientId,
                    'clientSecret' => $clientSecret,
                ])
                ->throw();
        } catch (RequestException $exception) {
            $this->logFailure('API client token request failed', $exception);

            throw new RuntimeException(__('n1co did not accept the API client.'), 0, $exception);
        }

        $token = $response->json('accessToken');

        if (! is_string($token) || $token === '') {
            $this->logFailure('API client token missing in response', null, [
                'response' => $this->safeBody($response->json()),
            ]);

            throw new RuntimeException(__('n1co did not accept the API client.'));
        }

        $seconds = (int) $response->json('expiresIn');
        Cache::put($cacheKey, $token, now()->addSeconds(max(60, $seconds - 60)));

        return $token;
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
            throw new RuntimeException(__('n1co is not configured.'));
        }

        return $secret;
    }

    private function webhookSecret(): string
    {
        $secret = instanceSettings()->n1co_webhook_secret;

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException(__('n1co is not configured.'));
        }

        return $secret;
    }

    private function httpErrorMessage(RequestException $exception, string $fallback): string
    {
        $body = $exception->response?->json();
        if (! is_array($body)) {
            return $fallback;
        }

        foreach (['message', 'Message', 'error', 'Error', 'title', 'Title', 'detail', 'Detail'] as $key) {
            $value = $body[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return 'n1co: '.trim($value);
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logFailure(string $reason, ?Throwable $exception = null, array $context = []): void
    {
        $payload = array_merge([
            'sandbox' => $this->sandbox(),
        ], $context);

        if ($exception instanceof RequestException) {
            try {
                $payload['http_status'] = $exception->response?->status();
                $payload['response'] = $this->safeBody(
                    $exception->response?->json() ?? $exception->response?->body()
                );
            } catch (Throwable) {
                $payload['response'] = '[unreadable]';
            }
        }

        if ($exception instanceof Throwable) {
            $payload['exception'] = $exception->getMessage();
        }

        $this->writeLog('n1co: '.$reason, $payload, 'error');

        if ($exception instanceof Throwable) {
            report($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function writeLog(string $message, array $context = [], string $level = 'error'): void
    {
        // PHP stderr always reaches `docker logs` even when config:cache is stale.
        error_log($message.' '.json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        try {
            Log::log($level, $message, $context);
        } catch (Throwable) {
        }
    }

    private function safeBody(mixed $body): mixed
    {
        if (is_string($body)) {
            return mb_substr($body, 0, 2000);
        }

        if (! is_array($body)) {
            return $body;
        }

        $redacted = $body;
        array_walk_recursive($redacted, function (mixed &$value, mixed $key): void {
            if (! is_string($key)) {
                return;
            }

            $name = strtolower($key);
            if (str_contains($name, 'secret') || str_contains($name, 'token') || str_contains($name, 'password') || str_contains($name, 'authorization')) {
                $value = '[redacted]';
            }
        });

        return $redacted;
    }
}
