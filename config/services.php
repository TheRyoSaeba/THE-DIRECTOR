<?php

return [



    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'discord' => [
        // Generic webhook — retained as a fallback for any code still
        // reading services.discord.webhook_url. New integrations should
        // use a channel-specific URL (e.g. changelog_webhook_url below)
        // so each channel can have its own webhook and rotate independently.
        'webhook_url'           => env('DISCORD_WEBHOOK_URL'),
        'changelog_webhook_url' => env('DISCORD_CHANGELOG_WEBHOOK_URL', env('DISCORD_WEBHOOK_URL')),
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
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'google_cloud' => [
        'project_id' => env('GOOGLE_CLOUD_PROJECT', env('GCP_PROJECT_ID')),
    ],

];
