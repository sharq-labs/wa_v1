<?php

$keywordList = static fn (string $envKey, string $fallback): array => array_values(array_filter(array_map(
    static fn (string $keyword): string => trim(mb_strtolower($keyword)),
    explode(',', (string) env($envKey, $fallback)),
)));

return [
    'provider' => env('WHATSAPP_PROVIDER', 'fake'),
    'allow_fake_accounts' => (bool) env('WHATSAPP_ALLOW_FAKE_ACCOUNTS', false),
    'service_window_hours' => (int) env('WHATSAPP_SERVICE_WINDOW_HOURS', 24),

    // System-level consent keywords are handled before automations. They change
    // messaging eligibility but still flow through the normal inbox/automation
    // pipeline so businesses can reply with their own confirmation message.
    'opt_in_keywords' => $keywordList('WHATSAPP_OPT_IN_KEYWORDS', 'start,subscribe,اشترك,ابدأ'),
    'opt_out_keywords' => $keywordList('WHATSAPP_OPT_OUT_KEYWORDS', 'stop,unsubscribe,توقف,إلغاء الاشتراك,الغاء الاشتراك'),

    // Disk used for both outbound uploads and provider-downloaded inbound media.
    // Use S3-compatible object storage in production when multiple app workers
    // need the same files.
    'media_disk' => env('WHATSAPP_MEDIA_DISK', 'public'),

    'inbound_media' => [
        'max_bytes' => (int) env('WHATSAPP_INBOUND_MEDIA_MAX_BYTES', 32 * 1024 * 1024),
        'download_timeout' => (int) env('WHATSAPP_INBOUND_MEDIA_TIMEOUT', 45),
        // Meta media download URLs currently resolve through these official CDN
        // families. Keep this configurable in case Meta changes delivery hosts.
        'allowed_host_suffixes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'WHATSAPP_INBOUND_MEDIA_ALLOWED_HOSTS',
                'facebook.com,fbcdn.net,fbsbx.com,whatsapp.net',
            )),
        ))),
        'allowed_mime_prefixes' => [
            'image/',
            'video/',
            'audio/',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
        ],
    ],

    'max_automation_steps' => (int) env('MAX_AUTOMATION_STEPS', 200),
    'max_automation_depth' => (int) env('MAX_AUTOMATION_DEPTH', 5),

    'http_node' => [
        'timeout_seconds' => (int) env('AUTOMATION_HTTP_TIMEOUT', 10),
        'max_response_bytes' => (int) env('AUTOMATION_HTTP_MAX_RESPONSE', 512 * 1024),
        'allowed_schemes' => ['http', 'https'],
    ],

    'campaign_chunk_size' => (int) env('CAMPAIGN_CHUNK_SIZE', 100),
    'campaign_messages_per_second' => (int) env('CAMPAIGN_MESSAGES_PER_SECOND', 10),
];
