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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google' => [
        'client_id' => '342482080139-o5hc0u1v9rejucgfi7h017tmijmops68.apps.googleusercontent.com',
        'client_secret' => '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',
        'redirect' => 'http://localhost:8000/login/google/callback',
        'guzzle' => [
            'verify' => false,
        ],
    ],

    'facebook' => [
        'client_id' => '1504742477945482',
        'client_secret' => 'a9dfd1fec65e3e65d1ccee4b312cb207',
        'redirect' => 'http://localhost:8000/login/facebook/callback',
        'guzzle' => [
            'verify' => false,
        ],
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5-mini'),
    ],

    'fraud_llm' => [
        'min_risk' => env('FRAUD_LLM_MIN_RISK', 40),
    ],

];
