<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Payments\Data\WebhookRequest;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/webhooks/payments/{gatewayCode}: stateless (api middleware, no
 * session or CSRF). A webhook only triggers server-side verification; it
 * never credits a wallet by itself. Responses carry no details.
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $gatewayCode, PaymentService $payments): JsonResponse
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = (string) ($values[0] ?? '');
        }

        $result = $payments->handleWebhook($gatewayCode, new WebhookRequest((string) $request->getContent(), $headers));

        return response()->json(['received' => $result->httpStatus === 200], $result->httpStatus);
    }
}
