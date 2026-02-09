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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
   
    //obtener el id de empresa de ten para usarlo en los servicios
    'ten' => [
        'empresa_id' => env('TEN_EMPRESA_ID'),
    ],
    'woocommerce' => [
        'client_key' => env('WC_CLIENT_KEY'),
        'client_secret' => env('WC_CLIENT_SECRET'),
        'base_url' => env('WC_BASE_URL', 'https://tu-tienda.com/wp-json/wc/v3'),
    ]

];
