<?php

namespace Plugins\PayPal;

use App\Jobs\ProcessPaymentWebhook;
use App\Models\GatewayCredential;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook handler do PayPal.
 * URL: POST /webhooks/gateways/paypal
 *
 * Eventos relevantes:
 *  - PAYMENT.CAPTURE.COMPLETED → marca pago
 *  - PAYMENT.CAPTURE.DENIED    → marca falhou
 *  - PAYMENT.CAPTURE.REFUNDED  → marca refunded
 *  - PAYMENT.CAPTURE.REVERSED  → marca refunded
 *  - CHECKOUT.ORDER.APPROVED   → ainda pending (será capturado pelo nosso backend)
 */
class PayPalWebhookHandler
{
    public function handle(Request $request, string $slug): JsonResponse
    {
        $payload = $request->getContent();
        if (! is_string($payload) || $payload === '') {
            return response()->json(['message' => 'Invalid request'], 400);
        }

        $event = json_decode($payload, true);
        if (! is_array($event)) {
            return response()->json(['message' => 'Invalid payload'], 400);
        }

        $eventType = (string) ($event['event_type'] ?? '');
        $resource = $event['resource'] ?? [];
        if (! is_array($resource)) {
            return response()->json(['received' => true]);
        }

        // Tentar extrair o order_id (referência ao Order interno) e transaction_id (orderID PayPal).
        // PayPal envia "supplementary_data.related_ids.order_id" no evento de capture.
        $paypalOrderId = $resource['supplementary_data']['related_ids']['order_id']
            ?? $resource['id']
            ?? null;

        $customId = $resource['custom_id']
            ?? ($resource['purchase_units'][0]['custom_id'] ?? null);

        if (! is_string($paypalOrderId) || $paypalOrderId === '') {
            Log::warning('PayPalWebhook: paypal order_id ausente', ['event_type' => $eventType]);
            return response()->json(['received' => true]);
        }

        // Localizar Order interno pelo gateway_id (orderID PayPal). Fallback: custom_id (externalId).
        $order = Order::where('gateway', 'paypal')
            ->where('gateway_id', $paypalOrderId)
            ->first();

        if (! $order && is_string($customId) && $customId !== '') {
            $order = Order::where('gateway', 'paypal')
                ->where('id', $customId)
                ->first();
        }

        if (! $order) {
            Log::warning('PayPalWebhook: order não encontrado', [
                'paypal_order_id' => $paypalOrderId,
                'custom_id' => $customId,
                'event_type' => $eventType,
            ]);
            return response()->json(['received' => true]);
        }

        $credential = GatewayCredential::forTenant($order->tenant_id)
            ->where('gateway_slug', 'paypal')
            ->where('is_connected', true)
            ->first();

        if (! $credential) {
            Log::warning('PayPalWebhook: credencial não encontrada', ['tenant_id' => $order->tenant_id]);
            return response()->json(['message' => 'Credential not found'], 400);
        }

        $credentials = $credential->getDecryptedCredentials();
        $webhookId = trim((string) ($credentials['webhook_id'] ?? ''));
        if ($webhookId === '') {
            Log::warning('PayPalWebhook: webhook_id não configurado', ['tenant_id' => $order->tenant_id]);
            return response()->json(['message' => 'Webhook not configured'], 400);
        }

        // Verificar assinatura via API PayPal.
        $headers = array_change_key_case($request->headers->all(), CASE_LOWER);
        $flatHeaders = [];
        foreach ($headers as $k => $v) {
            $flatHeaders[$k] = is_array($v) ? ($v[0] ?? '') : (string) $v;
        }

        $client = new PayPalClient($credentials);
        if (! $client->verifyWebhookSignature($flatHeaders, $event, $webhookId)) {
            Log::warning('PayPalWebhook: signature verification failed', [
                'event_type' => $eventType,
                'paypal_order_id' => $paypalOrderId,
            ]);
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        // Mapear evento → status e despachar.
        $statusMap = [
            'PAYMENT.CAPTURE.COMPLETED' => 'paid',
            'PAYMENT.CAPTURE.DENIED' => 'failed',
            'PAYMENT.CAPTURE.REFUNDED' => 'refunded',
            'PAYMENT.CAPTURE.REVERSED' => 'refunded',
            'CHECKOUT.ORDER.APPROVED' => 'pending',
            'CHECKOUT.ORDER.COMPLETED' => 'paid',
        ];

        $newStatus = $statusMap[$eventType] ?? null;
        if ($newStatus === null) {
            return response()->json(['received' => true]);
        }

        ProcessPaymentWebhook::dispatchSync('paypal', $paypalOrderId, $eventType, $newStatus, $event);

        return response()->json(['received' => true]);
    }
}
