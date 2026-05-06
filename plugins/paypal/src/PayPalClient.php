<?php

namespace Plugins\PayPal;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PayPal REST API client (Orders v2 + OAuth + Webhooks).
 * Docs: https://developer.paypal.com/docs/api/orders/v2/
 */
class PayPalClient
{
    /** @var array<string,string|bool> */
    private array $credentials;

    public function __construct(array $credentials)
    {
        $this->credentials = $credentials;
    }

    public function baseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    private function isSandbox(): bool
    {
        $val = $this->credentials['sandbox'] ?? false;
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    private function clientId(): string
    {
        return trim((string) ($this->credentials['client_id'] ?? ''));
    }

    private function clientSecret(): string
    {
        return trim((string) ($this->credentials['client_secret'] ?? ''));
    }

    /**
     * Get OAuth access token (cached). Throws on failure.
     */
    public function token(): string
    {
        $cacheKey = 'paypal:token:' . md5($this->clientId() . '|' . ($this->isSandbox() ? 'sb' : 'live'));
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if ($this->clientId() === '' || $this->clientSecret() === '') {
            throw new \RuntimeException('PayPal: client_id ou client_secret ausente.');
        }

        $response = Http::withBasicAuth($this->clientId(), $this->clientSecret())
            ->asForm()
            ->acceptJson()
            ->timeout(20)
            ->post($this->baseUrl() . '/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        if (! $response->successful()) {
            Log::warning('PayPal: token request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('PayPal: falha ao autenticar. Verifique client_id/client_secret.');
        }

        $data = $response->json();
        $token = $data['access_token'] ?? null;
        $expiresIn = (int) ($data['expires_in'] ?? 0);
        if (! is_string($token) || $token === '' || $expiresIn <= 0) {
            throw new \RuntimeException('PayPal: token inválido na resposta.');
        }

        // Cache 60s before expiry.
        Cache::put($cacheKey, $token, max(60, $expiresIn - 60));

        return $token;
    }

    /**
     * Create an order with intent=CAPTURE.
     *
     * @return array{id:string, status:string, raw:array}
     */
    public function createOrder(float $amount, string $currency, string $externalId, ?string $description = null): array
    {
        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $externalId,
                'custom_id' => $externalId,
                'description' => $description ?? ('Order ' . $externalId),
                'amount' => [
                    'currency_code' => strtoupper($currency),
                    'value' => number_format($amount, 2, '.', ''),
                ],
            ]],
        ];

        $response = Http::withToken($this->token())
            ->acceptJson()
            ->timeout(30)
            ->withHeaders(['PayPal-Request-Id' => $externalId])
            ->post($this->baseUrl() . '/v2/checkout/orders', $payload);

        if (! $response->successful()) {
            Log::warning('PayPal createOrder failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('PayPal: não foi possível criar a ordem.');
        }

        $data = $response->json();
        return [
            'id' => (string) ($data['id'] ?? ''),
            'status' => (string) ($data['status'] ?? ''),
            'raw' => $data,
        ];
    }

    /**
     * Capture an approved order.
     *
     * @return array{id:string, status:string, capture_id:?string, raw:array}
     */
    public function captureOrder(string $orderId): array
    {
        $response = Http::withToken($this->token())
            ->acceptJson()
            ->timeout(30)
            ->withHeaders([
                'PayPal-Request-Id' => 'capture-' . $orderId,
                'Content-Type' => 'application/json',
            ])
            ->withBody('{}', 'application/json')
            ->post($this->baseUrl() . '/v2/checkout/orders/' . $orderId . '/capture');

        if (! $response->successful()) {
            Log::warning('PayPal captureOrder failed', [
                'order_id' => $orderId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            $errorMsg = $this->extractErrorMessage($response->json());
            throw new \RuntimeException($errorMsg ?: 'PayPal: falha na captura do pagamento.');
        }

        $data = $response->json();
        $captureId = $data['purchase_units'][0]['payments']['captures'][0]['id'] ?? null;

        return [
            'id' => (string) ($data['id'] ?? ''),
            'status' => (string) ($data['status'] ?? ''),
            'capture_id' => $captureId !== null ? (string) $captureId : null,
            'raw' => $data,
        ];
    }

    /**
     * Get order details.
     *
     * @return array<string,mixed>|null
     */
    public function getOrder(string $orderId): ?array
    {
        $response = Http::withToken($this->token())
            ->acceptJson()
            ->timeout(20)
            ->get($this->baseUrl() . '/v2/checkout/orders/' . $orderId);

        if (! $response->successful()) {
            return null;
        }
        return $response->json();
    }

    /**
     * Verify webhook signature.
     * Docs: https://developer.paypal.com/api/rest/webhooks/rest/#link-verifywebhooksignature
     */
    public function verifyWebhookSignature(array $headers, array $body, string $webhookId): bool
    {
        if ($webhookId === '') {
            return false;
        }

        $payload = [
            'auth_algo' => $headers['paypal-auth-algo'] ?? '',
            'cert_url' => $headers['paypal-cert-url'] ?? '',
            'transmission_id' => $headers['paypal-transmission-id'] ?? '',
            'transmission_sig' => $headers['paypal-transmission-sig'] ?? '',
            'transmission_time' => $headers['paypal-transmission-time'] ?? '',
            'webhook_id' => $webhookId,
            'webhook_event' => $body,
        ];

        try {
            $response = Http::withToken($this->token())
                ->acceptJson()
                ->timeout(15)
                ->post($this->baseUrl() . '/v1/notifications/verify-webhook-signature', $payload);

            if (! $response->successful()) {
                Log::warning('PayPal verifyWebhookSignature HTTP error', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return false;
            }

            return ($response->json('verification_status') === 'SUCCESS');
        } catch (\Throwable $e) {
            Log::warning('PayPal verifyWebhookSignature threw', ['message' => $e->getMessage()]);
            return false;
        }
    }

    private function extractErrorMessage(?array $data): ?string
    {
        if (! is_array($data)) {
            return null;
        }
        $details = $data['details'][0] ?? null;
        if (is_array($details)) {
            $issue = (string) ($details['issue'] ?? '');
            $description = (string) ($details['description'] ?? '');
            if ($issue === 'INSTRUMENT_DECLINED' || str_contains(strtoupper($issue), 'DECLINE')) {
                return 'Cartão recusado. Verifique os dados ou tente outro cartão.';
            }
            if ($issue === 'PAYER_ACTION_REQUIRED') {
                return 'Ação adicional do comprador necessária. Tente novamente.';
            }
            if ($description !== '') {
                return 'PayPal: ' . $description;
            }
        }
        return $data['message'] ?? null;
    }
}
