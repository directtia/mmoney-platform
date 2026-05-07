<?php

namespace Plugins\PayPal;

use App\Gateways\Contracts\GatewayDriver;
use Illuminate\Support\Facades\Log;

/**
 * PayPal driver — internacional, cartão via Advanced Card Fields.
 * Não suporta PIX nem boleto.
 *
 * Fluxo:
 *   1. Frontend (Hosted Card Fields) cria a Order via /api/v1/paypal/create-order → recebe orderID
 *   2. Frontend envia orderID como payment_token
 *   3. Backend (createCardPayment) faz capture do orderID
 *   4. Webhook (PAYMENT.CAPTURE.COMPLETED) confirma o status
 */
class PayPalDriver implements GatewayDriver
{
    public function testConnection(array $credentials): bool
    {
        try {
            $client = new PayPalClient($credentials);
            $client->token();
            return true;
        } catch (\Throwable $e) {
            Log::debug('PayPalDriver testConnection failed', ['message' => $e->getMessage()]);
            return false;
        }
    }

    public function createPixPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $postbackUrl
    ): array {
        throw new \RuntimeException('PayPal não suporta PIX. Use cartão de crédito.');
    }

    public function createBoletoPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        string $notificationUrl
    ): array {
        throw new \RuntimeException('PayPal não suporta boleto. Use cartão de crédito.');
    }

    /**
     * Captura uma order PayPal previamente aprovada via Hosted Card Fields.
     *
     * @param  array{payment_token:string, currency?:string, card_mask?:string}  $card
     * @return array{transaction_id:string, status:string, capture_id?:string}
     */
    public function createCardPayment(
        array $credentials,
        float $amount,
        array $consumer,
        string $externalId,
        array $card
    ): array {
        $orderId = trim((string) ($card['payment_token'] ?? ''));
        if ($orderId === '') {
            throw new \RuntimeException('PayPal: order_id (payment_token) ausente. Refaça o pagamento.');
        }

        try {
            $client = new PayPalClient($credentials);
            $result = $client->captureOrder($orderId);

            $status = $this->mapStatus($result['status'] ?? '');

            return [
                'transaction_id' => $result['id'] ?: $orderId,
                'status' => $status,
                'capture_id' => $result['capture_id'] ?? null,
            ];
        } catch (\RuntimeException $e) {
            // Mensagem amigável já tratada em PayPalClient::extractErrorMessage
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('PayPalDriver createCardPayment error', [
                'order_id' => $externalId,
                'paypal_order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Não foi possível processar o pagamento. Tente novamente.');
        }
    }

    public function getTransactionStatus(string $transactionId, array $credentials): ?string
    {
        try {
            $client = new PayPalClient($credentials);
            $data = $client->getOrder($transactionId);
            if ($data === null) {
                return null;
            }
            // Status real depende do Capture (dentro do Order). Order pode estar COMPLETED
            // mas o Capture estar REFUNDED (refund sem voidar a order).
            $captureStatus = $data['purchase_units'][0]['payments']['captures'][0]['status'] ?? null;
            if (is_string($captureStatus) && $captureStatus !== '') {
                $mapped = $this->mapCaptureStatus($captureStatus);
                if ($mapped !== null) {
                    return $mapped;
                }
            }
            return $this->mapStatus((string) ($data['status'] ?? ''));
        } catch (\Throwable $e) {
            Log::debug('PayPalDriver getTransactionStatus failed', [
                'tx' => $transactionId,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Mapeia status do Order PayPal pra padrão interno.
     * Statuses: CREATED, SAVED, APPROVED, VOIDED, COMPLETED, PAYER_ACTION_REQUIRED
     */
    private function mapStatus(string $paypalStatus): string
    {
        return match (strtoupper($paypalStatus)) {
            'COMPLETED' => 'paid',
            'VOIDED' => 'cancelled',
            'CREATED', 'SAVED', 'APPROVED', 'PAYER_ACTION_REQUIRED' => 'pending',
            default => 'pending',
        };
    }

    /**
     * Mapeia status do Capture (mais granular que o Order — diferencia paid/refunded).
     * Statuses: COMPLETED, DECLINED, PARTIALLY_REFUNDED, PENDING, REFUNDED, FAILED
     */
    private function mapCaptureStatus(string $captureStatus): ?string
    {
        return match (strtoupper($captureStatus)) {
            'COMPLETED' => 'paid',
            'REFUNDED', 'PARTIALLY_REFUNDED' => 'cancelled', // ProcessPaymentWebhook reconfirma com whitelist ['cancelled'] pra refund
            'DECLINED', 'FAILED' => 'cancelled',
            'PENDING' => 'pending',
            default => null,
        };
    }
}
