<?php

use App\Models\Message;
use App\Models\WebhookEvent;
use App\Services\Messaging\MetaMediaDownloader;
use App\Services\Messaging\WhatsAppEventProcessor;
use Illuminate\Support\Facades\Queue;

it('stores downloaded Meta media on the inbound inbox message', function () {
    Queue::fake();
    $ctx = createWorkspaceContext();
    $ctx['account']->forceFill([
        'phone_number_id' => 'phone-media-test',
        'access_token' => 'encrypted-by-cast',
        'provider' => 'meta',
    ])->save();

    $downloader = Mockery::mock(MetaMediaDownloader::class);
    $downloader->shouldReceive('download')
        ->once()
        ->withArgs(fn ($account, $mediaId) => $account->is($ctx['account']) && $mediaId === 'media_123')
        ->andReturn([
            'url' => 'https://cdn.example.test/whatsapp/inbound/photo.jpg',
            'path' => 'whatsapp/inbound/1/2026/08/photo.jpg',
            'disk' => 's3',
            'mime_type' => 'image/jpeg',
            'size' => 2048,
            'media_id' => 'media_123',
        ]);
    app()->instance(MetaMediaDownloader::class, $downloader);

    $event = WebhookEvent::query()->create([
        'provider' => 'meta',
        'event_id' => 'media-event-'.uniqid(),
        'event_type' => 'messages',
        'payload' => [
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'phone-media-test'],
                        'contacts' => [[
                            'wa_id' => '201111222333',
                            'profile' => ['name' => 'Media Customer'],
                        ]],
                        'messages' => [[
                            'id' => 'wamid.media.123',
                            'from' => '201111222333',
                            'timestamp' => '1786183200',
                            'type' => 'image',
                            'image' => [
                                'id' => 'media_123',
                                'mime_type' => 'image/jpeg',
                                'caption' => 'Product photo',
                            ],
                        ]],
                    ],
                ]],
            ]],
        ],
        'status' => 'pending',
    ]);

    app(WhatsAppEventProcessor::class)->process($event);

    $message = Message::query()->where('provider_message_id', 'wamid.media.123')->firstOrFail();

    expect($message->message_type->value)->toBe('image')
        ->and($message->content)->toBe('Product photo')
        ->and($message->media_url)->toBe('https://cdn.example.test/whatsapp/inbound/photo.jpg')
        ->and($message->media_mime_type)->toBe('image/jpeg')
        ->and(data_get($message->payload, 'media.id'))->toBe('media_123')
        ->and(data_get($message->payload, 'media.size'))->toBe(2048)
        ->and(data_get($message->payload, 'raw.image.id'))->toBe('media_123');
});

it('keeps the inbound message when Meta media download fails', function () {
    Queue::fake();
    $ctx = createWorkspaceContext();
    $ctx['account']->forceFill([
        'phone_number_id' => 'phone-media-failure',
        'access_token' => 'encrypted-by-cast',
        'provider' => 'meta',
    ])->save();

    $downloader = Mockery::mock(MetaMediaDownloader::class);
    $downloader->shouldReceive('download')->once()->andThrow(new RuntimeException('temporary CDN failure'));
    app()->instance(MetaMediaDownloader::class, $downloader);

    $event = WebhookEvent::query()->create([
        'provider' => 'meta',
        'event_id' => 'media-failure-event-'.uniqid(),
        'event_type' => 'messages',
        'payload' => [
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'phone-media-failure'],
                        'messages' => [[
                            'id' => 'wamid.media.failure',
                            'from' => '201999888777',
                            'type' => 'document',
                            'document' => [
                                'id' => 'media_failure',
                                'mime_type' => 'application/pdf',
                                'caption' => 'Invoice',
                            ],
                        ]],
                    ],
                ]],
            ]],
        ],
        'status' => 'pending',
    ]);

    app(WhatsAppEventProcessor::class)->process($event);

    $message = Message::query()->where('provider_message_id', 'wamid.media.failure')->firstOrFail();

    expect($message->content)->toBe('Invoice')
        ->and($message->media_url)->toBeNull()
        ->and(data_get($message->payload, 'media_download_error'))->toContain('temporary CDN failure')
        ->and(data_get($message->payload, 'raw.document.id'))->toBe('media_failure');
});
