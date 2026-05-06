<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Core gateways (slug => definition).
    | Plugins may register additional gateways via GatewayRegistry::register().
    |--------------------------------------------------------------------------
    */
    'gateways' => [
        'stripe' => [
            'slug' => 'stripe',
            'name' => 'Stripe',
            'image' => 'images/gateways/stripe.png',
            'methods' => ['card'],
            'scope' => 'international',
            'country_flag' => 'global.png',
            'country_name' => 'Global',
            'signup_url' => 'https://dashboard.stripe.com/register',
            'driver' => \App\Gateways\Stripe\StripeDriver::class,
            'credential_keys' => [
                ['key' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password'],
                ['key' => 'publishable_key', 'label' => 'Publishable Key', 'type' => 'text'],
                ['key' => 'webhook_secret', 'label' => 'Webhook Secret (whsec_...)', 'type' => 'password'],
                ['key' => 'sandbox', 'label' => 'Usar ambiente de teste', 'type' => 'boolean'],
                ['key' => 'link_enabled', 'label' => 'Habilitar Stripe Link no checkout', 'type' => 'boolean'],
            ],
        ],
        'mercadopago' => [
            'slug' => 'mercadopago',
            'name' => 'Mercado Pago',
            'image' => 'images/gateways/mercado-pago.webp',
            'methods' => ['pix', 'card', 'boleto'],
            'scope' => 'international',
            'country' => 'br',
            'country_name' => 'Brasil, Argentina, Chile, Colômbia, México, Peru, Uruguai',
            'country_flag' => 'brasil.png',
            'countries' => [
                ['flag' => 'brasil.png', 'name' => 'Brasil'],
                ['flag' => 'argentina.png', 'name' => 'Argentina'],
                ['flag' => 'chile.png', 'name' => 'Chile'],
                ['flag' => 'colombia.png', 'name' => 'Colômbia'],
                ['flag' => 'mexico.png', 'name' => 'México'],
                ['flag' => 'peru.png', 'name' => 'Peru'],
                ['flag' => 'uruguay.png', 'name' => 'Uruguai'],
            ],
            'signup_url' => 'https://www.mercadopago.com.br/developers',
            'driver' => \App\Gateways\MercadoPago\MercadoPagoDriver::class,
            'credential_keys' => [
                ['key' => 'public_key', 'label' => 'Public Key', 'type' => 'text'],
                ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password'],
                ['key' => 'sandbox', 'label' => 'Usar sandbox (credenciais de teste)', 'type' => 'boolean'],
            ],
        ],
        'asaas' => [
            'slug' => 'asaas',
            'name' => 'Asaas',
            'image' => 'images/gateways/asaas.png',
            'methods' => ['pix', 'card', 'boleto'],
            'scope' => 'national',
            'country' => 'br',
            'country_name' => 'Brasil',
            'country_flag' => 'brasil.png',
            'signup_url' => 'https://www.asaas.com',
            'driver' => \App\Gateways\Asaas\AsaasDriver::class,
            'credential_keys' => [
                ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password'],
                ['key' => 'sandbox', 'label' => 'Usar ambiente de homologação (sandbox)', 'type' => 'boolean'],
            ],
        ],
        'pagarme' => [
            'slug' => 'pagarme',
            'name' => 'Pagar.me',
            'image' => 'images/gateways/pagarme.png',
            'methods' => ['pix', 'card', 'boleto'],
            'scope' => 'national',
            'country' => 'br',
            'country_name' => 'Brasil',
            'country_flag' => 'brasil.png',
            'signup_url' => 'https://pagar.me',
            'driver' => \App\Gateways\Pagarme\PagarmeDriver::class,
            'checkout_payload_keys' => ['public_key'],
            'credential_keys' => [
                ['key' => 'secret_key', 'label' => 'Secret Key', 'type' => 'password'],
                ['key' => 'public_key', 'label' => 'Public Key', 'type' => 'text'],
                ['key' => 'sandbox', 'label' => 'Sandbox', 'type' => 'boolean'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default redundancy order per method (when tenant has not configured).
    |--------------------------------------------------------------------------
    */
    'default_order' => [
        'pix' => ['mercadopago', 'pagarme', 'asaas'],
        'card' => ['stripe', 'mercadopago', 'pagarme', 'asaas'],
        'boleto' => ['mercadopago', 'pagarme', 'asaas'],
        'pix_auto' => [],
        'crypto' => [],
    ],
];
