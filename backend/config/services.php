<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'public_key' => env('STRIPE_PUBLIC_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        // Canadian platform (Optional)
        'ca_secret_key' => env('STRIPE_CA_SECRET_KEY', env('STRIPE_SECRET_KEY')),
        'ca_public_key' => env('STRIPE_CA_PUBLIC_KEY', env('STRIPE_PUBLIC_KEY')),
        'ca_webhook_secret' => env('STRIPE_CA_WEBHOOK_SECRET', env('STRIPE_WEBHOOK_SECRET')),

        // Irish platform (Optional)
        'ie_secret_key' => env('STRIPE_IE_SECRET_KEY', env('STRIPE_SECRET_KEY')),
        'ie_public_key' => env('STRIPE_IE_PUBLIC_KEY', env('STRIPE_PUBLIC_KEY')),
        'ie_webhook_secret' => env('STRIPE_IE_WEBHOOK_SECRET', env('STRIPE_WEBHOOK_SECRET')),

        // Primary platform for new organizers
        'primary_platform' => env('STRIPE_PRIMARY_PLATFORM'),
    ],
    'open_exchange_rates' => [
        'app_id' => env('OPEN_EXCHANGE_RATES_APP_ID'),
    ],

    // Added by Passix: Sign in with Google.
    // The ID token flow needs no client secret — the client ID is public and the token
    // is verified against Google's published JWKS, so there is nothing secret to leak.
    'google' => [
        'enabled' => (bool)env('GOOGLE_AUTH_ENABLED', false),
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'jwks_url' => env('GOOGLE_JWKS_URL', 'https://www.googleapis.com/oauth2/v3/certs'),
        'jwks_cache_ttl_seconds' => (int)env('GOOGLE_JWKS_CACHE_TTL_SECONDS', 3600),
        // Google signs with either issuer; both are valid per the OpenID discovery document.
        'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
        // Tolerance for clock skew between our server and Google when checking exp/iat.
        'leeway_seconds' => (int)env('GOOGLE_AUTH_LEEWAY_SECONDS', 60),
        // How long a browser has to complete sign in after we hand it a nonce.
        'nonce_ttl_seconds' => (int)env('GOOGLE_NONCE_TTL_SECONDS', 600),
        // How long a half-finished Google signup may sit on the "complete your details" screen.
        'registration_token_ttl_seconds' => (int)env('GOOGLE_REGISTRATION_TOKEN_TTL_SECONDS', 900),
    ],

    // Added by Passix: Cloudflare Turnstile (anti-bot CAPTCHA on the public checkout)
    'turnstile' => [
        'enabled' => (bool)env('TURNSTILE_ENABLED', false),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => env('TURNSTILE_VERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
    ],
];
