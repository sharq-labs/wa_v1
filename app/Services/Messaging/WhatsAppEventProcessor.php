<?php

namespace App\Services\Messaging;

use App\Enums\MessageStatus;
use App\Enums\TemplateStatus;
use App\Events\MessageStatusChanged;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Facades\Log;

/**
 * Turns a stored Meta webhook event into domain changes: inbound messages,
 * delivery status updates and account/template updates.
 */
class WhatsAppEventProcessor
{
    public function __construct(protected InboundMessageService $inbound) {}

    public function process(WebhookEvent $event): void
    {
        $payload = $event->payload;

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $field = $change['field'] ?? '';
                $value = $change['value'] ?? [];

                match ($field) {
                    'messages' => $this->handleMessages($event, $value),
                    'message_template_status_update' => $this->handleTemplateStatus($value),
                    default => Log::info('Unhandled webhook field', ['field' => $field]),
                };
            }
        }
    }

    protected function handleMessages(WebhookEvent $event, array $value): void
    {
        $account = $this->resolveAccount($value);

        if (! $account) {
            Log::warning('Webhook for unknown phone_number_id', [
                'phone_number_id' => $value['metadata']['phone_number_id'] ?? null,
            ]);

            return;
        }

        if ($event->workspace_id === null) {
            $event->forceFill(['workspace_id' => $account->workspace_id])->save();
        }

        $profiles = collect($value['contacts'] ?? [])->keyBy('wa_id');

        foreach ($value['messages'] ?? [] as $message) {
            $this->inbound->ingest($account, $this->normaliseMessage($message, $profiles));
        }

        foreach ($value['statuses'] ?? [] as $status) {
            $this->handleStatus($account, $status);
        }
    }

    protected function resolveAccount(array $value): ?WhatsAppAccount
    {
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

        if (! $phoneNumberId) {
            return null;
        }

        return WhatsAppAccount::query()->where('phone_number_id', $phoneNumberId)->first();
    }

    protected function normaliseMessage(array $message, $profiles): array
    {
        $type = $message['type'] ?? 'unknown';
        $waId = $message['from'] ?? '';

        $text = match ($type) {
            'text' => $message['text']['body'] ?? null,
            'button' => $message['button']['text'] ?? null,
            'interactive' => $message['interactive']['button_reply']['title']
                ?? $message['interactive']['list_reply']['title']
                ?? null,
            'image', 'video', 'document' => $message[$type]['caption'] ?? null,
            'location' => isset($message['location'])
                ? trim(($message['location']['name'] ?? '').' '.($message['location']['latitude'] ?? '').','.($message['location']['longitude'] ?? ''))
                : null,
            'reaction' => $message['reaction']['emoji'] ?? null,
            default => null,
        };

        $payload = ['raw' => $message];

        if ($type === 'interactive') {
            $payload['reply_id'] = $message['interactive']['button_reply']['id']
                ?? $message['interactive']['list_reply']['id']
                ?? null;
        }
        if ($type === 'button') {
            $payload['reply_id'] = $message['button']['payload'] ?? null;
        }

        return [
            'provider_message_id' => $message['id'],
            'wa_id' => $waId,
            'phone_number' => $waId,
            'profile_name' => $profiles[$waId]['profile']['name'] ?? null,
            'type' => $type,
            'text' => $text,
            'media_url' => null, // media requires a follow-up Graph download; stored id kept in payload
            'media_mime_type' => $message[$type]['mime_type'] ?? null,
            'payload' => $payload,
            'timestamp' => isset($message['timestamp']) ? (int) $message['timestamp'] : null,
        ];
    }

    protected function handleStatus(WhatsAppAccount $account, array $status): void
    {
        $message = Message::query()
            ->where('workspace_id', $account->workspace_id)
            ->where('provider_message_id', $status['id'] ?? '')
            ->first();

        if (! $message) {
            return;
        }

        $newStatus = match ($status['status'] ?? '') {
            'sent' => MessageStatus::Sent,
            'delivered' => MessageStatus::Delivered,
            'read' => MessageStatus::Read,
            'failed' => MessageStatus::Failed,
            default => null,
        };

        if (! $newStatus) {
            return;
        }

        // Never downgrade a status (e.g. late "delivered" after "read").
        $order = [
            MessageStatus::Queued->value => 0,
            MessageStatus::Sent->value => 1,
            MessageStatus::Delivered->value => 2,
            MessageStatus::Read->value => 3,
            MessageStatus::Failed->value => 4,
        ];

        if (($order[$newStatus->value] ?? 0) <= ($order[$message->status->value] ?? 0)) {
            return;
        }

        $updates = ['status' => $newStatus];

        match ($newStatus) {
            MessageStatus::Delivered => $updates['delivered_at'] = now(),
            MessageStatus::Read => $updates['read_at'] = now(),
            MessageStatus::Failed => $updates['failed_at'] = now(),
            default => null,
        };

        if ($newStatus === MessageStatus::Failed) {
            $error = $status['errors'][0] ?? [];
            $updates['error_code'] = (string) ($error['code'] ?? '');
            $updates['error_message'] = $error['title'] ?? ($error['message'] ?? null);
        }

        $message->forceFill($updates)->save();

        broadcast(new MessageStatusChanged($message->workspace_id, [
            'message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'status' => $newStatus->value,
        ]));
    }

    protected function handleTemplateStatus(array $value): void
    {
        $templateId = $value['message_template_id'] ?? null;

        if (! $templateId) {
            return;
        }

        $template = WhatsAppTemplate::query()
            ->where('meta_template_id', (string) $templateId)
            ->first();

        if (! $template) {
            return;
        }

        $template->update([
            'status' => match (strtoupper($value['event'] ?? '')) {
                'APPROVED' => TemplateStatus::Approved,
                'REJECTED' => TemplateStatus::Rejected,
                'PAUSED' => TemplateStatus::Paused,
                'DISABLED' => TemplateStatus::Disabled,
                default => $template->status,
            },
            'rejection_reason' => $value['reason'] ?? $template->rejection_reason,
            'last_synced_at' => now(),
        ]);
    }
}
