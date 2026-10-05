<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\GetOdooServerOrder;
use App\Services\GetOdoo\SettleGetOdooServerPayment;
use App\Services\GetOdoo\WompiClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class Wompi extends Controller
{
    public function __invoke(Request $request, WompiClient $wompi, SettleGetOdooServerPayment $settle): Response
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

        $order = GetOdooServerOrder::query()
            ->where('uuid', (string) data_get($payload, 'EnlacePago.IdentificadorEnlaceComercio'))
            ->first();

        if (! $order instanceof GetOdooServerOrder) {
            return response('ok', 200);
        }

        $approved = data_get($payload, 'ResultadoTransaccion') === 'ExitosaAprobada';
        $live = filter_var(data_get($payload, 'EsProductiva'), FILTER_VALIDATE_BOOLEAN);
        $paid = $wompi->sameMoney(data_get($payload, 'Monto'), $order->amount);

        if (! $approved || ! $live || ! $paid) {
            return response('ok', 200);
        }

        try {
            $settle->settle($order, (string) data_get($payload, 'IdTransaccion'));
        } catch (Throwable $exception) {
            report($exception);

            return response('The server could not be created.', 500);
        }

        return response('ok', 200);
    }
}
