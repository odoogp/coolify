<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\GetOdooPlan;
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

        if (! is_array($payload)) {
            $this->remember($eventId);

            return response('ok', 200);
        }

        $type = (string) data_get($payload, 'type');

        if (in_array($type, ['SubscriptionConfirmation', 'SubscriptionPayment', 'SubscriptionCancelled'], true)) {
            return $this->settleSubscription($payload, $n1co, $settle, $plans, $eventId);
        }

        if ($type !== 'SuccessPayment') {
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

        if ($code === '' && $order->charge_mode !== GetOdooPlan::CHARGE_SUBSCRIPTION) {
            return response('ok', 200);
        }

        try {
            $remote = $order->charge_mode === GetOdooPlan::CHARGE_SUBSCRIPTION
                ? $n1co->paidSubscription($order->n1co_plan_id, $order->amount)
                : $n1co->order($code);
        } catch (Throwable $exception) {
            report($exception);

            return response('The payment could not be confirmed.', 500);
        }

        if ($order->charge_mode === GetOdooPlan::CHARGE_SUBSCRIPTION) {
            if (! is_array($remote)) {
                $this->remember($eventId);

                return response('ok', 200);
            }

            try {
                $settle->settle($order, $this->subscriptionTransactionId($remote, $code));
                $this->rememberSubscription($order, $remote);
            } catch (Throwable $exception) {
                report($exception);

                return response('The server could not be created.', 500);
            }

            $this->remember($eventId);

            return response('ok', 200);
        }

        if (! is_array($remote) || ! $n1co->isPaid($remote, $order->amount)) {
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

        if ($code === '' && $signup->charge_mode !== GetOdooPlan::CHARGE_SUBSCRIPTION) {
            return response('ok', 200);
        }

        try {
            $remote = $signup->charge_mode === GetOdooPlan::CHARGE_SUBSCRIPTION
                ? $n1co->paidSubscription($signup->n1co_plan_id, $signup->amount)
                : $n1co->order($code);
        } catch (Throwable $exception) {
            report($exception);

            return response('The payment could not be confirmed.', 500);
        }

        if ($signup->charge_mode === GetOdooPlan::CHARGE_SUBSCRIPTION) {
            if (! is_array($remote)) {
                $this->remember($eventId);

                return response('ok', 200);
            }

            try {
                $plans->settle($signup, $this->subscriptionTransactionId($remote, $code));
                $this->rememberSubscription($signup, $remote);
            } catch (Throwable $exception) {
                report($exception);

                return response('The account could not be created.', 500);
            }

            $this->remember($eventId);

            return response('ok', 200);
        }

        if (! is_array($remote) || ! $n1co->isPaid($remote, $signup->amount)) {
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
    private function settleSubscription(array $payload, N1coClient $n1co, SettleGetOdooServerPayment $settle, SettleGetOdooPlanSignup $plans, string $eventId): Response
    {
        $type = (string) data_get($payload, 'type');
        $subscriptionId = trim((string) data_get($payload, 'metadata.SubscriptionId', data_get($payload, 'subscriptionId', '')));
        $uuid = $this->referenceFromSubscription($payload);
        $order = $this->findOrder($subscriptionId, $uuid);
        $signup = $order instanceof GetOdooServerOrder ? null : $this->findSignup($subscriptionId, $uuid);

        if ($type === 'SubscriptionCancelled') {
            $order?->update(['n1co_subscription_status' => 'cancelled']);
            $signup?->update(['n1co_subscription_status' => 'cancelled']);
            $this->remember($eventId);

            return response('ok', 200);
        }

        if ($order instanceof GetOdooServerOrder) {
            return $this->applyServerSubscription($order, $payload, $n1co, $settle, $eventId, $type);
        }

        if ($signup instanceof GetOdooPlanSignup) {
            return $this->applyPlanSubscription($signup, $payload, $n1co, $plans, $eventId, $type);
        }

        $this->remember($eventId);

        return response('ok', 200);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyServerSubscription(GetOdooServerOrder $order, array $payload, N1coClient $n1co, SettleGetOdooServerPayment $settle, string $eventId, string $type): Response
    {
        $price = data_get($payload, 'metadata.SubscriptionPrice');

        if (! $n1co->sameMoney($price, $order->amount)) {
            $this->remember($eventId);

            return response('ok', 200);
        }

        $subscriptionId = trim((string) data_get($payload, 'metadata.SubscriptionId', ''));

        try {
            if ($type === 'SubscriptionPayment' && in_array($order->status, ['provisioned', 'paid'], true) && $order->server_id) {
                $settle->recordRenewal($order);
            } else {
                $settle->settle($order, $subscriptionId !== '' ? $subscriptionId : 'n1co-'.$order->uuid);
            }
        } catch (Throwable $exception) {
            report($exception);

            return response('The server could not be created.', 500);
        }

        if ($subscriptionId !== '') {
            $order->update([
                'n1co_subscription_id' => $subscriptionId,
                'n1co_subscription_status' => 'active',
            ]);
        }

        $this->remember($eventId);

        return response('ok', 200);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyPlanSubscription(GetOdooPlanSignup $signup, array $payload, N1coClient $n1co, SettleGetOdooPlanSignup $plans, string $eventId, string $type): Response
    {
        $price = data_get($payload, 'metadata.SubscriptionPrice');

        if (! $n1co->sameMoney($price, $signup->amount)) {
            $this->remember($eventId);

            return response('ok', 200);
        }

        $subscriptionId = trim((string) data_get($payload, 'metadata.SubscriptionId', ''));

        try {
            if (! ($type === 'SubscriptionPayment' && $signup->status === 'paid')) {
                $plans->settle($signup, $subscriptionId !== '' ? $subscriptionId : 'n1co-'.$signup->uuid);
            }
        } catch (Throwable $exception) {
            report($exception);

            return response('The account could not be created.', 500);
        }

        if ($subscriptionId !== '') {
            $signup->update([
                'n1co_subscription_id' => $subscriptionId,
                'n1co_subscription_status' => 'active',
            ]);
        }

        $this->remember($eventId);

        return response('ok', 200);
    }

    private function findOrder(string $subscriptionId, ?string $uuid): ?GetOdooServerOrder
    {
        if ($subscriptionId !== '') {
            $found = GetOdooServerOrder::query()->where('n1co_subscription_id', $subscriptionId)->first();

            if ($found instanceof GetOdooServerOrder) {
                return $found;
            }
        }

        if ($uuid === null || $uuid === '') {
            return null;
        }

        $found = GetOdooServerOrder::query()->where('uuid', $uuid)->first();

        return $found instanceof GetOdooServerOrder ? $found : null;
    }

    private function findSignup(string $subscriptionId, ?string $uuid): ?GetOdooPlanSignup
    {
        if ($subscriptionId !== '') {
            $found = GetOdooPlanSignup::query()->where('n1co_subscription_id', $subscriptionId)->first();

            if ($found instanceof GetOdooPlanSignup) {
                return $found;
            }
        }

        if ($uuid === null || $uuid === '') {
            return null;
        }

        $found = GetOdooPlanSignup::query()->where('uuid', $uuid)->first();

        return $found instanceof GetOdooPlanSignup ? $found : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function referenceFromSubscription(array $payload): ?string
    {
        $reference = trim((string) data_get($payload, 'metadata.OrderReference', data_get($payload, 'orderReference', '')));

        if ($reference !== '') {
            return $reference;
        }

        $name = (string) data_get($payload, 'metadata.SubscriptionName', '');

        if (preg_match('/\s([A-Za-z0-9]{16,})$/', $name, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function rememberSubscription(GetOdooServerOrder|GetOdooPlanSignup $record, array $remote): void
    {
        $subscriptionId = trim((string) ($remote['subscriptionId'] ?? ''));

        if ($subscriptionId === '') {
            return;
        }

        $record->update([
            'n1co_subscription_id' => $subscriptionId,
            'n1co_subscription_status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function subscriptionTransactionId(array $remote, string $orderCode): string
    {
        $subscriptionId = trim((string) ($remote['subscriptionId'] ?? ''));

        return $subscriptionId !== '' ? $subscriptionId : 'n1co-'.$orderCode;
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
