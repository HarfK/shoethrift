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

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    | HitPay (https://docs.hitpayapp.com). Keys live only in .env.
    | salt: the salt of the *webhook endpoint* (Developers → Webhook Endpoints), used to verify
    |       the Hitpay-Signature header. It is not the salt shown with the API keys.
    | api_url: https://api.sandbox.hit-pay.com (sandbox) or https://api.hit-pay.com (live).
    */
    'hitpay' => [
        'api_key' => env('HITPAY_API_KEY'),
        'salt' => env('HITPAY_SALT'),
        'api_url' => env('HITPAY_API_URL', 'https://api.sandbox.hit-pay.com'),
        // Public HTTPS base (e.g. ngrok) for the webhook URL; see `php artisan hitpay:webhook-url`.
        'public_url' => env('HITPAY_PUBLIC_URL'),
        'currency' => env('HITPAY_CURRENCY', 'MYR'),
        'payment_window_minutes' => (int) env('HITPAY_PAYMENT_WINDOW_MINUTES', 30),
    ],

    /*
    | ML service (ml-service/, FastAPI on 127.0.0.1): revenue forecast, restock report, price suggestions.
    | The key must match ML_API_KEY in ml-service/.env. Never commit it. See App\Services\Ml\MlService.
    */
    'ml' => [
        'url' => env('ML_API_URL', 'http://127.0.0.1:8001'),
        'key' => env('ML_API_KEY'),
        'timeout' => (int) env('ML_API_TIMEOUT', 5),
        'connect_timeout' => (int) env('ML_API_CONNECT_TIMEOUT', 2),
        'retrain_timeout' => (int) env('ML_API_RETRAIN_TIMEOUT', 30),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
