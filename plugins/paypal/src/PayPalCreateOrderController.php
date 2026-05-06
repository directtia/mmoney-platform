<?php

namespace Plugins\PayPal;

use App\Models\GatewayCredential;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * POST /checkout/paypal/create-order
 *
 * Recebe { product_id, amount, currency } do checkout JS.
 * Resolve credenciais do tenant pelo product_id, cria Order na API PayPal,
 * retorna orderID pra Hosted Card Fields finalizar tokenização no front.
 *
 * Segurança: o amount é capturado quando /checkout POST faz a captura final.
 * O PaymentService valida o amount real contra o produto/cupom no submit.
 */
class PayPalCreateOrderController
{
    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer|min:1',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'external_id' => 'nullable|string|max:64',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Parâmetros inválidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $product = Product::find($data['product_id']);
        if (! $product) {
            return response()->json(['message' => 'Produto não encontrado.'], 404);
        }

        $credential = GatewayCredential::forTenant($product->tenant_id)
            ->where('gateway_slug', 'paypal')
            ->where('is_connected', true)
            ->first();

        if (! $credential) {
            return response()->json(['message' => 'PayPal não configurado para este produto.'], 400);
        }

        try {
            $credentials = $credential->getDecryptedCredentials();
            $client = new PayPalClient($credentials);
            $externalId = $data['external_id'] ?? ('co-' . bin2hex(random_bytes(8)));
            $result = $client->createOrder(
                (float) $data['amount'],
                strtoupper($data['currency']),
                $externalId,
                'Checkout — ' . ($product->name ?? ('Produto #' . $product->id))
            );

            return response()->json([
                'orderID' => $result['id'],
                'status' => $result['status'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('PayPalCreateOrder failed', [
                'product_id' => $data['product_id'],
                'message' => $e->getMessage(),
            ]);
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
