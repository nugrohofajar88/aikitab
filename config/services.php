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

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'extra_keys' => env('GEMINI_API_KEYS_EXTRA'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'model_priority' => env('GEMINI_MODEL_PRIORITY'),
    ],

    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'google/gemini-2.5-flash'),
    ],

    'ai' => [
        'provider' => env('AI_PROVIDER', 'gemini'),
        'fallback_provider' => env('AI_FALLBACK_PROVIDER'),
    ],

    'hosted' => [
        'url' => env('HOSTED_API_URL'),
        'token' => env('HOSTED_SYNC_TOKEN'),
    ],

    // Raw FTPS credentials for HostedSyncService::ftpUpload() — deliberately
    // not a Storage disk (see config/filesystems.php history / AGENTS.md):
    // Flysystem's FTP adapter buffers writes through an in-memory stream that
    // hit the same temp-file bug as the old single-JSON-POST publish did.
    'hosted_ftp' => [
        'host' => env('HOSTED_FTP_HOST'),
        'username' => env('HOSTED_FTP_USERNAME'),
        'password' => env('HOSTED_FTP_PASSWORD'),
        'port' => (int) env('HOSTED_FTP_PORT', 21),
        'root' => env('HOSTED_FTP_ROOT'),
    ],

    'ghostscript' => [
        'binary' => env('GHOSTSCRIPT_BINARY', 'gs'),
    ],

];
