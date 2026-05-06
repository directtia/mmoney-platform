<?php

use App\Gateways\GatewayRegistry;
use Illuminate\Support\Facades\Route;

require_once __DIR__ . '/src/PayPalClient.php';
require_once __DIR__ . '/src/PayPalDriver.php';
require_once __DIR__ . '/src/PayPalWebhookHandler.php';
require_once __DIR__ . '/src/PayPalCreateOrderController.php';

return function ($app, \Illuminate\Contracts\Events\Dispatcher $events): void {
    GatewayRegistry::register([
        'slug' => 'paypal',
        'name' => 'PayPal',
        'image' => 'plugin:paypal/logo.png',
        'methods' => ['card'],
        'scope' => 'international',
        'country' => 'global',
        'country_flag' => 'global.png',
        'country_name' => 'Global',
        'signup_url' => 'https://www.paypal.com/businesssignup',
        'driver' => \Plugins\PayPal\PayPalDriver::class,
        'credential_keys' => [
            ['key' => 'client_id',     'label' => 'Client ID',     'type' => 'text'],
            ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password'],
            ['key' => 'webhook_id',    'label' => 'Webhook ID',    'type' => 'text'],
            ['key' => 'sandbox',       'label' => 'Usar ambiente sandbox', 'type' => 'boolean'],
        ],
        'webhook_handler' => \Plugins\PayPal\PayPalWebhookHandler::class,
        'checkout_payload_keys' => ['client_id', 'sandbox'],
    ]);

    // Endpoint pra Hosted Card Fields criar a Order PayPal antes de tokenizar.
    Route::post('/checkout/paypal/create-order', \Plugins\PayPal\PayPalCreateOrderController::class)
        ->middleware(['web', 'throttle:30,1'])
        ->name('checkout.paypal.create-order');
};
