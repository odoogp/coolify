<?php

namespace App\Services\GetOdoo;

use App\Models\GetOdooPlanSignup;
use App\Models\GetOdooServerOrder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WompiClient
{
    public function configured(): bool
    {
        $settings = instanceSettings();

        return filled($settings->wompi_client_id) && filled($settings->wompi_client_secret);
    }

    public function createServerLink(GetOdooServerOrder $order): array
    {
        $order->loadMissing('offer');
        $offer = $order->offer;
        $name = trim((string) ($offer?->description ?: $offer?->name ?: 'GetOdoo'));

        return $this->enlace(
            $order->uuid,
            (float) $order->amount,
            'GetOdoo '.$name,
            $name.' · '.$order->location.' · '.__('per month'),
            route('getodoo.wompi.return', ['order' => $order->uuid]),
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

        return $this->enlace(
            $signup->uuid,
            (float) $signup->amount,
            product_name().' '.$name,
            trim((string) ($plan->summary ?: $name)),
            route('getodoo.plan.return', ['plan' => $plan->uuid, 'signup' => $signup->uuid]),
        );
    }

    /**
     * @return array{id: string, url: string}
     */
    private function enlace(string $identificador, float $amount, string $product, string $description, string $redirect): array
    {
        $response = $this->request()->post('https://api.wompi.sv/EnlacePago', [
            'identificadorEnlaceComercio' => $identificador,
            'monto' => $amount,
            'nombreProducto' => $product,
            'formaPago' => [
                'permitirTarjetaCreditoDebido' => true,
                'permitirPagoConPuntoAgricola' => false,
                'permitirPagoEnCuotasAgricola' => false,
                'permitirPagoEnBitcoin' => false,
                'permitePagoQuickPay' => false,
            ],
            'infoProducto' => [
                'descripcionProducto' => $description,
            ],
            'configuracion' => [
                'urlRedirect' => $redirect,
                'esMontoEditable' => false,
                'esCantidadEditable' => false,
                'urlWebhook' => route('getodoo.wompi.webhook'),
                'notificarTransaccionCliente' => true,
            ],
            'limitesDeUso' => [
                'cantidadMaximaPagosExitosos' => 1,
            ],
        ])->throw();

        $url = $response->json('urlEnlace');

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Wompi did not return a payment link.');
        }

        return [
            'id' => (string) $response->json('idEnlace'),
            'url' => $url,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transaction(string $id): array
    {
        $payload = $this->request()
            ->get('https://api.wompi.sv/TransaccionCompra/'.$id)
            ->throw()
            ->json();

        return is_array($payload) ? $payload : [];
    }

    public function webhookHash(string $body): string
    {
        return hash_hmac('sha256', $body, $this->secret());
    }

    public function paymentLinkHash(string $identificador, string $transactionId, string $linkId, string $amount): string
    {
        return hash_hmac('sha256', $identificador.$transactionId.$linkId.$amount, $this->secret());
    }

    public function hashMatches(string $expected, string $given): bool
    {
        $given = strtolower(trim($given));

        return $given !== '' && strlen($given) === strlen($expected) && hash_equals($expected, $given);
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    public function chargeIsLiveAndApproved(array $transaction, GetOdooServerOrder $order): bool
    {
        return $this->liveApprovedAmount($transaction, $order->amount);
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    public function liveApprovedAmount(array $transaction, mixed $amount): bool
    {
        $approved = filter_var(data_get($transaction, 'esAprobada', data_get($transaction, 'EsAprobada')), FILTER_VALIDATE_BOOLEAN);
        $live = filter_var(
            data_get($transaction, 'esReal', data_get($transaction, 'EsReal', data_get($transaction, 'EsProductiva'))),
            FILTER_VALIDATE_BOOLEAN,
        );

        return $approved && $live && $this->sameMoney(data_get($transaction, 'monto', data_get($transaction, 'Monto')), $amount);
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    public function isTestCharge(array $transaction): bool
    {
        $live = data_get($transaction, 'esReal', data_get($transaction, 'EsReal', data_get($transaction, 'EsProductiva')));

        return $live !== null && filter_var($live, FILTER_VALIDATE_BOOLEAN) === false;
    }

    public function sameMoney(mixed $paid, mixed $expected): bool
    {
        return number_format((float) $paid, 2, '.', '') === number_format((float) $expected, 2, '.', '');
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::acceptJson()
            ->withToken($this->accessToken())
            ->timeout(20);
    }

    private function accessToken(): string
    {
        $clientId = (string) instanceSettings()->wompi_client_id;

        return Cache::remember('wompi-token-'.sha1($clientId), now()->addMinutes(4), function () use ($clientId) {
            try {
                $response = Http::asForm()
                    ->acceptJson()
                    ->timeout(20)
                    ->post('https://id.wompi.sv/connect/token', [
                        'grant_type' => 'client_credentials',
                        'audience' => 'wompi_api',
                        'client_id' => $clientId,
                        'client_secret' => $this->secret(),
                    ])
                    ->throw();
            } catch (RequestException $exception) {
                throw new RuntimeException('Wompi did not accept the credentials.', 0, $exception);
            }

            $token = $response->json('access_token');

            if (! is_string($token) || $token === '') {
                throw new RuntimeException('Wompi did not return an access token.');
            }

            return $token;
        });
    }

    private function secret(): string
    {
        $secret = instanceSettings()->wompi_client_secret;

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('Wompi is not configured.');
        }

        return $secret;
    }
}
