<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Meta WhatsApp Business Platform
    |--------------------------------------------------------------------------
    |
    | Configuration for the Meta Graph API integration, Embedded Signup and
    | webhook verification. The Graph API version is configurable and must
    | never be hard-coded anywhere else in the codebase.
    |
    */

    'app_id' => env('META_APP_ID'),
    'app_secret' => env('META_APP_SECRET'),
    'config_id' => env('META_CONFIG_ID'),
    'embedded_signup_config_id' => env('META_EMBEDDED_SIGNUP_CONFIG_ID'),
    'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN', 'change-me'),
    'graph_api_version' => env('META_GRAPH_API_VERSION', 'v21.0'),
    'redirect_uri' => env('META_REDIRECT_URI'),

    'graph_base_url' => env('META_GRAPH_BASE_URL', 'https://graph.facebook.com'),
];
