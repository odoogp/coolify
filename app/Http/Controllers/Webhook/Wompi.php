<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\GetOdooPlanSignup;
use App\Models\GetOdooServerOrder;
use App\Services\GetOdoo\SettleGetOdooPlanSignup;
use App\Services\GetOdoo\SettleGetOdooServerPayment;
use App\Services\GetOdoo\WompiClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class Wompi extends Controller
{
    public function __invoke(Request $request, WompiClient $wompi, SettleGetOdooServerPayment $settle, SettleGetOdooPlanSignup $plans): Response
    {
        if (! $wompi->configured()) {
            return response('Wompi is not configured.', 404);
        }

        $body = $request->getContent();
        $given = (string) $request->header('wompi_hash', '');

        if (! $wompi->hashMatches($wompi->webhookHash($body), $given)) {
            return response('Invalid signature.', 400);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return response('ok', 200);
        }

        $identificador = (string) data_get($payload, 'EnlacePago.IdentificadorEnlaceComercio');
        $approved = data_get($payload, 'ResultadoTransaccion') === 'ExitosaAprobada';
        $live = filter_var(data_get($payload, 'EsProductiva'), FILTER_VALIDATE_BOOLEAN);
        $transactionId = (string) data_get($payload, 'IdTransaccion');

        $order = GetOdooServerOrder::query()->where('uuid', $identificador)->first();

        if ($order instanceof GetOdooServerOrder) {
            if (! $approved || ! $live || ! $wompi->sameMoney(data_get($payload, 'Monto'), $order->amount)) {
                return response('ok', 200);
            }

            try {
                $settle->settle($order, $transactionId);
            } catch (Throwable $exception) {
                report($exception);

                return response('The server could not be created.', 500);
            }

            return response('ok', 200);
        }

        $signup = GetOdooPlanSignup::query()->where('uuid', $identificador)->first();

        if (! $signup instanceof GetOdooPlanSignup) {
            return response('ok', 200);
        }

        if (! $approved || ! $live || ! $wompi->sameMoney(data_get($payload, 'Monto'), $signup->amount)) {
            return response('ok', 200);
        }

        try {
            $plans->settle($signup, $transactionId);
        } catch (Throwable $exception) {
            report($exception);

            return response('The account could not be created.', 500);
        }

        return response('ok', 200);
    }
}
