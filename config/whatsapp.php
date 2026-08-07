<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Messaging provider
    |--------------------------------------------------------------------------
    |
    | fake  - in-memory provider for development, testing and Meta review demos
    | meta  - official Meta WhatsApp Cloud API
    |
    */

    'provider' => env('WHATSAPP_PROVIDER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Customer service window
    |--------------------------------------------------------------------------
    |
    | WhatsApp only allows free-form messages within this many hours of the
    | last inbound customer message. Outside the window an approved template
    | must be used. Centralised here so no policy is hard-coded in the UI.
    |
    */

    'service_window_hours' => (int) env('WHATSAPP_SERVICE_WINDOW_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Automation engine safety limits
    |--------------------------------------------------------------------------
    */

    'max_automation_steps' => (int) env('MAX_AUTOMATION_STEPS', 200),
    'max_automation_depth' => (int) env('MAX_AUTOMATION_DEPTH', 5),

    /*
    |--------------------------------------------------------------------------
    | HTTP Request node (SSRF protection)
    |--------------------------------------------------------------------------
    */

    'http_node' => [
        'timeout_seconds' => (int) env('AUTOMATION_HTTP_TIMEOUT', 10),
        'max_response_bytes' => (int) env('AUTOMATION_HTTP_MAX_RESPONSE', 512 * 1024),
        'allowed_schemes' => ['http', 'https'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Campaigns
    |--------------------------------------------------------------------------
    */

    'campaign_chunk_size' => (int) env('CAMPAIGN_CHUNK_SIZE', 100),
    'campaign_messages_per_second' => (int) env('CAMPAIGN_MESSAGES_PER_SECOND', 10),
];
