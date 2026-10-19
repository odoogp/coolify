<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\GetOdooPlanSignup;
use App\Models\GetOdooServerOrder;
use App\Services\GetOdoo\N1coClient;
use App\Services\GetOdoo\SettleGetOdooPlanSignup;
use App\Services\GetOdoo\SettleGetOdooServerPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

class N1co extends Controller
{
    public function __invoke(Request $request, N1coClient $n1co, SettleGetOdooServerPayment $settle, SettleGetOdooPlanSignup $plans): Response
    {
        if (! $n1co->configured()) {
            return response('n1co is not configured.', 404);
        }

        $body = $request->getContent();
        $given = (string) $request->header('X-H4B-Hmac-Sha256', '');

        if (! $n1co->hashMatches($n1co->webhookHash($body), $given)) {
            return response('Invalid signature.', 401);
        }

        $eventId = trim((string) $request->header('X-H4B-Event-Id', ''));

        if ($eventId !== '' && Cache::has($this->eventKey($eventId))) {
            return response('ok', 200);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload) || data_get($payload, 'type') !== 'SuccessPayment') {
            $this->remember($eventId);

            return response('ok', 200);
        }

        $reference = (string) data_get($payload, 'orderReference');
        $order = $reference !== ''
            ? GetOdooServerOrder::query()->where('uuid', $reference)->first()
            : null;

        if ($order instanceof GetOdooServerOrder) {
            return $this->settleOrder($order, $payload, $n1co, $settle, $eventId);
        }

        $signup = $reference !== ''
            ? GetOdooPlanSignup::query()->where('uuid', $reference)->first()
            : null;

        if (! $signup instanceof GetOdooPlanSignup) {
            $this->remember($eventId);

            return response('ok', 200);
        }

        return $this->settleSignup($signup, $payload, $n1co, $plans, $eventId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function settleOrder(GetOdooServerOrder $order, array $payload, N1coClient $n1co, SettleGetOdooServerPayment $settle, string $eventId): Response
    {
        $code = (string) $order->wompi_link_id;

        if ($code === '') {
            return response('ok', 200);
        }

        try {
            $remote = $n1co->order($code);
        } catch (Throwable $exception) {
            report($exception);

            return response('The payment could not be confirmed.', 500);
        }

        if (! $n1co->isPaid($remote, $order->amount)) {
            $this->remember($eventId);

            return response('ok', 200);
        }

        try {
            $settle->settle($order, $this->transactionId($payload, $code));
        } catch (Throwable $exception) {
            report($exception);

            return response('The server could not be created.', 500);
        }

        $this->remember($eventId);

        return response('ok', 200);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function settleSignup(GetOdooPlanSignup $signup, array $payload, N1coClient $n1co, SettleGetOdooPlanSignup $plans, string $eventId): Response
    {
        $code = (string) $signup->wompi_link_id;

        if ($code === '') {
            return response('ok', 200);
        }

        try {
            $remote = $n1co->order($code);
        } catch (Throwable $exception) {
            report($exception);

            return response('The payment could not be confirmed.', 500);
        }

        if (! $n1co->isPaid($remote, $signup->amount)) {
            $this->remember($eventId);

            return response('ok', 200);
        }

        try {
            $plans->settle($signup, $this->transactionId($payload, $code));
        } catch (Throwable $exception) {
            report($exception);

            return response('The account could not be created.', 500);
        }

        $this->remember($eventId);

        return response('ok', 200);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function transactionId(array $payload, string $orderCode): string
    {
        $paymentId = (string) data_get($payload, 'metadata.PaymentId', '');

        return $paymentId !== '' ? $paymentId : 'n1co-'.$orderCode;
    }

    private function remember(string $eventId): void
    {
        if ($eventId === '') {
            return;
        }

        Cache::put($this->eventKey($eventId), true, now()->addDays(2));
    }

    private function eventKey(string $eventId): string
    {
        return 'n1co-event-'.$eventId;
    }
}
