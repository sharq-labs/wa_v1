<?php

namespace App\Services\Messaging;

use App\Enums\MessageStatus;
use App\Enums\TemplateStatus;
use App\Events\MessageStatusChanged;
use App\Models\CampaignRecipient;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a stored Meta webhook event into domain changes: inbound messages,
 * delivery status updates and account/template updates.
 */
class WhatsAppEventProcessor
{
    public function __construct(
        protected InboundMessageService $inbound,
        protected MetaMediaDownloader $mediaDownloader,
    ) {}

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
            $this->inbound->ingest($account, $this->normaliseMessage($account, $message, $profiles));
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

    protected function normaliseMessage(WhatsAppAccount $account, array $message, $profiles): array
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

        $mediaUrl = null;
        $mediaMimeType = $message[$type]['mime_type'] ?? null;
        if (in_array($type, ['image', 'video', 'audio', 'document'], true)) {
            $mediaId = trim((string) ($message[$type]['id'] ?? ''));
            if ($mediaId !== '') {
                try {
                    $download = $this->mediaDownloader->download($account, $mediaId);
                    $mediaUrl = $download['url'];
                    $mediaMimeType = $download['mime_type'];
                    $payload['media'] = [
                        'id' => $download['media_id'],
                        'disk' => $download['disk'],
                        'path' => $download['path'],
                        'size' => $download['size'],
                        'mime_type' => $download['mime_type'],
                    ];
                } catch (Throwable $e) {
                    // Never drop the inbound message because the remote media
                    // download failed. Keep the provider media id in raw payload
                    // so an operator can retry/recover it later.
                    $payload['media_download_error'] = Str::limit($e->getMessage(), 500);
                    Log::warning('Inbound WhatsApp media download failed.', [
                        'account_id' => $account->id,
                        'media_id' => $mediaId,
                        'message_id' => $message['id'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return [
            'provider_message_id' => $message['id'],
            'wa_id' => $waId,
            'phone_number' => $waId,
            'profile_name' => $profiles[$waId]['profile']['name'] ?? null,
            'type' => $type,
            'text' => $text,
            'media_url' => $mediaUrl,
            'media_mime_type' => $mediaMimeType,
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

        $previousStatus = $message->status;
        if (($order[$newStatus->value] ?? 0) <= ($order[$previousStatus->value] ?? 0)) {
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
        $this->syncCampaignStatus($message, $previousStatus, $newStatus);

        broadcast(new MessageStatusChanged($message->workspace_id, [
            'message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'status' => $newStatus->value,
        ]));
    }

    protected function syncCampaignStatus(Message $message, MessageStatus $previousStatus, MessageStatus $newStatus): void
    {
        $recipient = CampaignRecipient::query()
            ->with('campaign')
            ->where('message_id', $message->id)
            ->first();

        if (! $recipient || ! $recipient->campaign) {
            return;
        }

        $campaign = $recipient->campaign;
        $rank = [
            MessageStatus::Queued->value => 0,
            MessageStatus::Sent->value => 1,
            MessageStatus::Delivered->value => 2,
            MessageStatus::Read->value => 3,
            MessageStatus::Failed->value => 4,
        ];
        $previousRank = $rank[$previousStatus->value] ?? 0;

        if ($newStatus === MessageStatus::Delivered) {
            if ($previousRank < $rank[MessageStatus::Delivered->value]) {
                $campaign->increment('delivered_count');
            }
            $recipient->update(['status' => 'delivered', 'error_message' => null]);

            return;
        }

        if ($newStatus === MessageStatus::Read) {
            // Meta can occasionally skip the delivered callback and go directly
            // from sent to read. A read implicitly means delivered.
            if ($previousRank < $rank[MessageStatus::Delivered->value]) {
                $campaign->increment('delivered_count');
            }
            if ($previousRank < $rank[MessageStatus::Read->value]) {
                $campaign->increment('read_count');
            }
            $recipient->update(['status' => 'read', 'error_message' => null]);

            return;
        }

        if ($newStatus === MessageStatus::Failed) {
            // Immediate provider failures are already counted by the send job.
            // A webhook failure after Meta first accepted the message is new.
            if ($previousStatus !== MessageStatus::Failed) {
                $campaign->increment('failed_count');
            }
            $recipient->update([
                'status' => 'failed',
                'error_message' => $message->error_message,
            ]);
        }
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
