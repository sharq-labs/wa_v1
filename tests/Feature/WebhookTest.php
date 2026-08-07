<?php

use App\Enums\WebhookEventStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WebhookEvent;

function metaWebhookPayload(string $phoneNumberId, string $waId, string $text, string $messageId): array
{
    return [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'waba-entry',
            'changes' => [[
                'field' => 'messages',
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['display_phone_number' => '20100', 'phone_number_id' => $phoneNumberId],
                    'contacts' => [['profile' => ['name' => 'Webhook Customer'], 'wa_id' => $waId]],
                    'messages' => [[
                        'from' => $waId,
                        'id' => $messageId,
                        'timestamp' => (string) time(),
                        'type' => 'text',
                        'text' => ['body' => $text],
                    ]],
                ],
            ]],
        ]],
    ];
}

it('verifies the webhook handshake with the correct token', function () {
    config(['meta.webhook_verify_token' => 'verify-me']);

    $this->get('/api/webhooks/meta/whatsapp?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=12345')
        ->assertOk()
        ->assertSee('12345');
});

it('rejects the handshake with a wrong token', function () {
    config(['meta.webhook_verify_token' => 'verify-me']);

    $this->get('/api/webhooks/meta/whatsapp?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=12345')
        ->assertForbidden();
});

it('rejects events with an invalid signature when an app secret is configured', function () {
    config(['meta.app_secret' => 'topsecret']);

    $payload = ['entry' => []];

    $this->call(
        'POST',
        '/api/webhooks/meta/whatsapp',
        [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid'],
        json_encode($payload),
    )->assertForbidden();
});

it('accepts events with a valid signature', function () {
    config(['meta.app_secret' => 'topsecret']);

    $ctx = createWorkspaceContext();
    $payload = metaWebhookPayload($ctx['account']->phone_number_id, '201055555555', 'hello', 'wamid.sig.1');
    $body = json_encode($payload);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'topsecret');

    $this->call(
        'POST',
        '/api/webhooks/meta/whatsapp',
        [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature],
        $body,
    )->assertOk();

    expect(WebhookEvent::query()->count())->toBe(1)
        ->and(Message::query()->where('provider_message_id', 'wamid.sig.1')->exists())->toBeTrue();
});

it('stores, processes and ingests an inbound message end to end', function () {
    $ctx = createWorkspaceContext();

    $payload = metaWebhookPayload($ctx['account']->phone_number_id, '201044444444', 'مرحبا', 'wamid.e2e.1');

    $this->postJson('/api/webhooks/meta/whatsapp', $payload)->assertOk();

    $message = Message::query()->where('provider_message_id', 'wamid.e2e.1')->first();

    expect($message)->not->toBeNull()
        ->and($message->content)->toBe('مرحبا')
        ->and($message->conversation->contact->display_name)->toBe('Webhook Customer')
        ->and(WebhookEvent::query()->value('status'))->toBe(WebhookEventStatus::Processed);
});

it('never duplicates anything when the same webhook is delivered twice', function () {
    $ctx = createWorkspaceContext();

    $payload = metaWebhookPayload($ctx['account']->phone_number_id, '201033333333', 'hello twice', 'wamid.dup.1');

    $this->postJson('/api/webhooks/meta/whatsapp', $payload)->assertOk();
    $this->postJson('/api/webhooks/meta/whatsapp', $payload)->assertOk();

    expect(WebhookEvent::query()->count())->toBe(1)
        ->and(Message::query()->where('provider_message_id', 'wamid.dup.1')->count())->toBe(1)
        ->and(Contact::query()->where('wa_id', '201033333333')->count())->toBe(1)
        ->and(Conversation::query()->count())->toBe(1);
});
