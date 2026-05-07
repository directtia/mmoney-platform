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

        // Resolver o orderID PayPal original (que está no nosso DB como gateway_id).
        // Para PAYMENT.CAPTURE.COMPLETED → vem em supplementary_data.related_ids.order_id.
        // Para PAYMENT.CAPTURE.REFUNDED/REVERSED → resource é o refund, precisamos
        //   extrair capture_id do link "up" e consultar o capture na API.
        $paypalOrderId = $resource['supplementary_data']['related_ids']['order_id'] ?? null;

        if (! $paypalOrderId) {
            $captureId = null;
            foreach (($resource['links'] ?? []) as $link) {
                $href = (string) ($link['href'] ?? '');
                if (($link['rel'] ?? '') === 'up' && str_contains($href, '/captures/')) {
                    $captureId = basename($href);
                    break;
                }
            }
            if ($captureId) {
                $paypalOrderId = $this->resolveOrderIdFromCapture($captureId);
            }
        }

        $customId = $resource['custom_id']
            ?? ($resource['purchase_units'][0]['custom_id'] ?? null);

        if (! is_string($paypalOrderId) || $paypalOrderId === '') {
            Log::warning('PayPalWebhook: paypal order_id nao resolvido', [
                'event_type' => $eventType,
                'resource_id' => $resource['id'] ?? null,
            ]);
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

    /**
     * Para webhooks de refund/reverse o resource é o refund (não tem related_ids do order original).
     * Buscamos o capture na API PayPal pra extrair supplementary_data.related_ids.order_id.
     * Itera sobre credenciais conectadas até achar uma que resolva (multi-tenant safe).
     */
    private function resolveOrderIdFromCapture(string $captureId): ?string
    {
        $credentials = GatewayCredential::where('gateway_slug', 'paypal')
            ->where('is_connected', true)
            ->get();

        foreach ($credentials as $cred) {
            try {
                $creds = $cred->getDecryptedCredentials();
                $client = new PayPalClient($creds);
                $token = $client->token();
                $base = !empty($creds['sandbox']) ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

                $resp = \Illuminate\Support\Facades\Http::withToken($token)
                    ->acceptJson()
                    ->timeout(15)
                    ->get($base . '/v2/payments/captures/' . $captureId);

                if ($resp->successful()) {
                    $orderId = $resp->json('supplementary_data.related_ids.order_id');
                    if (is_string($orderId) && $orderId !== '') {
                        return $orderId;
                    }
                }
            } catch (\Throwable $e) {
                Log::debug('PayPalWebhook: failed to resolve via tenant', [
                    'tenant_id' => $cred->tenant_id,
                    'capture_id' => $captureId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }
}
