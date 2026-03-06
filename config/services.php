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

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI'),
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => env('GITHUB_REDIRECT_URI'),
    ],

    /*
    |--------------------------------------------------------------------------
    | School System Third Party Services
    |--------------------------------------------------------------------------
    |
    | Additional services for school management system
    |
    */

    'sms' => [
        'twilio' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'auth_token' => env('TWILIO_AUTH_TOKEN'),
            'phone_number' => env('TWILIO_PHONE_NUMBER'),
            'enabled' => env('TWILIO_ENABLED', false),
        ],
        'africastalking' => [
            'username' => env('AFRICASTALKING_USERNAME'),
            'api_key' => env('AFRICASTALKING_API_KEY'),
            'short_code' => env('AFRICASTALKING_SHORT_CODE'),
            'enabled' => env('AFRICASTALKING_ENABLED', false),
        ],
        'termii' => [
            'api_key' => env('TERMII_API_KEY'),
            'sender_id' => env('TERMII_SENDER_ID'),
            'enabled' => env('TERMII_ENABLED', false),
        ],
    ],

    'payment' => [
        'paystack' => [
            'public_key' => env('PAYSTACK_PUBLIC_KEY'),
            'secret_key' => env('PAYSTACK_SECRET_KEY'),
            'merchant_email' => env('PAYSTACK_MERCHANT_EMAIL'),
            'callback_url' => env('PAYSTACK_CALLBACK_URL'),
            'enabled' => env('PAYSTACK_ENABLED', false),
        ],
        'flutterwave' => [
            'public_key' => env('FLUTTERWAVE_PUBLIC_KEY'),
            'secret_key' => env('FLUTTERWAVE_SECRET_KEY'),
            'encryption_key' => env('FLUTTERWAVE_ENCRYPTION_KEY'),
            'callback_url' => env('FLUTTERWAVE_CALLBACK_URL'),
            'enabled' => env('FLUTTERWAVE_ENABLED', false),
        ],
        'stripe' => [
            'key' => env('STRIPE_KEY'),
            'secret' => env('STRIPE_SECRET'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
            'enabled' => env('STRIPE_ENABLED', false),
        ],
    ],

    'analytics' => [
        'google_analytics' => [
            'tracking_id' => env('GOOGLE_ANALYTICS_TRACKING_ID'),
            'enabled' => env('GOOGLE_ANALYTICS_ENABLED', false),
        ],
        'matomo' => [
            'url' => env('MATOMO_URL'),
            'site_id' => env('MATOMO_SITE_ID'),
            'token' => env('MATOMO_TOKEN'),
            'enabled' => env('MATOMO_ENABLED', false),
        ],
    ],

    'storage' => [
        'aws' => [
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
        ],
        'digitalocean' => [
            'key' => env('DIGITALOCEAN_KEY'),
            'secret' => env('DIGITALOCEAN_SECRET'),
            'region' => env('DIGITALOCEAN_REGION'),
            'bucket' => env('DIGITALOCEAN_BUCKET'),
            'endpoint' => env('DIGITALOCEAN_ENDPOINT'),
        ],
    ],

    'maps' => [
        'google_maps' => [
            'api_key' => env('GOOGLE_MAPS_API_KEY'),
            'enabled' => env('GOOGLE_MAPS_ENABLED', false),
        ],
        'mapbox' => [
            'access_token' => env('MAPBOX_ACCESS_TOKEN'),
            'enabled' => env('MAPBOX_ENABLED', false),
        ],
    ],

    'calendar' => [
        'google_calendar' => [
            'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
            'redirect_uri' => env('GOOGLE_CALENDAR_REDIRECT_URI'),
            'enabled' => env('GOOGLE_CALENDAR_ENABLED', false),
        ],
    ],

    'notification' => [
        'onesignal' => [
            'app_id' => env('ONESIGNAL_APP_ID'),
            'rest_api_key' => env('ONESIGNAL_REST_API_KEY'),
            'user_auth_key' => env('ONESIGNAL_USER_AUTH_KEY'),
            'enabled' => env('ONESIGNAL_ENABLED', false),
        ],
        'firebase' => [
            'credentials' => env('FIREBASE_CREDENTIALS'),
            'database_url' => env('FIREBASE_DATABASE_URL'),
            'enabled' => env('FIREBASE_ENABLED', false),
        ],
    ],

    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        'enabled' => env('RECAPTCHA_ENABLED', false),
    ],

];