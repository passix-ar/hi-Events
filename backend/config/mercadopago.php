<?php

return [
    'client_id'            => env('MP_CLIENT_ID', ''),
    'client_secret'        => env('MP_CLIENT_SECRET', ''),
    'platform_access_token' => env('MP_PLATFORM_ACCESS_TOKEN', ''),
    'webhook_secret'       => env('MP_WEBHOOK_SECRET', ''),
    'marketplace_fee_percent' => (float) env('MP_MARKETPLACE_FEE_PERCENT', '0'),
    'redirect_uri'         => env('MP_OAUTH_REDIRECT_URI', ''),
    'sandbox'              => env('MP_SANDBOX', true),
    'auth_url'             => 'https://auth.mercadopago.com.ar/authorization',
    'token_url'            => 'https://api.mercadopago.com/oauth/token',
];
