<?php

namespace App\Services\Messaging;

use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\MessageStatusChanged;
use App\Events\NewMessage;
use App\Http\Resources\MessageResource;
use App\Jobs\SendOutboundMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppTemplate;

/**
 * Creates outbound message records and queues delivery via the provider.
 */
class MessageService
{
    /**
     * @param  array{sender_type?: MessageSenderType, sender_user?: ?User, reply_to?: ?int, sync?: bool}  $options
     */
    public function sendText(Conversation $conversation, string $text, array $options = []): Message
    {
        return $this->createOutbound($conversation, MessageType::Text, [
            'content' => $text,
        ], $options);
    }

    public function sendMedia(Conversation $conversation, MessageType $type, string $url, ?string $caption = null, ?string $mime = null, array $options = []): Message
    {
        return $this->createOutbound($conversation, $type, [
            'content' => $caption,
            'media_url' => $url,
            'media_mime_type' => $mime,
        ], $options);
    }

    /**
     * @param  array<int, array{id: string, title: string}>  $buttons
     */
    public function sendButtons(Conversation $conversation, string $body, array $buttons, array $options = []): Message
    {
        return $this->createOutbound($conversation, MessageType::Interactive, [
            'content' => $body,
            'payload' => [
                'interactive' => [
                    'type' => 'button',
                    'body' => $body,
                    'buttons' => $buttons,
                    'header' => $options['header'] ?? null,
                    'footer' => $options['footer'] ?? null,
                ],
            ],
        ], $options);
    }

    public function sendList(Conversation $conversation, string $body, string $buttonLabel, array $sections, array $options = []): Message
    {
        return $this->createOutbound($conversation, MessageType::Interactive, [
            'content' => $body,
            'payload' => [
                'interactive' => [
                    'type' => 'list',
                    'body' => $body,
                    'button' => $buttonLabel,
                    'sections' => $sections,
                    'header' => $options['header'] ?? null,
                    'footer' => $options['footer'] ?? null,
                ],
            ],
        ], $options);
    }

    /**
     * @param  array  $components  resolved Meta-format template components
     * @param  string  $renderedText  human-readable preview stored as content
     */
    public function sendTemplate(Conversation $conversation, WhatsAppTemplate $template, array $components, string $renderedText, array $options = []): Message
    {
        $message = $this->createOutbound($conversation, MessageType::Template, [
            'content' => $renderedText,
            'template_name' => $template->name,
            'payload' => [
                'template' => [
                    'name' => $template->name,
                    'language' => $template->language,
                    'components' => $components,
                ],
            ],
        ], $options);

        $template->increment('usage_count');

        return $message;
    }

    protected function createOutbound(Conversation $conversation, MessageType $type, array $attributes, array $options = []): Message
    {
        $senderType = $options['sender_type'] ?? MessageSenderType::Agent;
        $senderUser = $options['sender_user'] ?? null;

        $message = Message::query()->create(array_merge([
            'workspace_id' => $conversation->workspace_id,
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'whatsapp_account_id' => $conversation->whatsapp_account_id,
            'sender_user_id' => $senderUser?->id,
            'direction' => MessageDirection::Outbound,
            'sender_type' => $senderType,
            'message_type' => $type,
            'status' => MessageStatus::Queued,
            'reply_to_message_id' => $options['reply_to'] ?? null,
        ], $attributes));

        $conversation->forceFill(['last_message_at' => now()])->save();

        broadcast(new NewMessage($conversation->workspace_id, [
            'message' => MessageResource::make($message)->resolve(),
            'conversation_id' => $conversation->id,
        ]));

        if (! empty($options['sync'])) {
            SendOutboundMessage::dispatchSync($message->id);
        } else {
            SendOutboundMessage::dispatch($message->id)->onQueue('whatsapp-messages');
        }

        return $message->refresh();
    }

    /**
     * Deliver an already-created outbound message through the provider.
     * Called from the SendOutboundMessage job.
     */
    public function deliver(Message $message, MessagingManager $manager): void
    {
        $account = $message->whatsappAccount;
        $conversation = $message->conversation;

        if (! $account || ! $conversation) {
            $this->markFailed($message, 'no_account', 'Conversation has no WhatsApp account.');

            return;
        }

        $provider = $manager->forAccount($account);
        $to = $message->contact?->wa_id ?? $message->contact?->phone_number;

        if (! $to) {
            $this->markFailed($message, 'no_recipient', 'Contact has no WhatsApp id.');

            return;
        }

        $result = match ($message->message_type) {
            MessageType::Text => $provider->sendText($account, $to, (string) $message->content, [
                'reply_to' => $message->replyTo?->provider_message_id,
            ]),
            MessageType::Image => $provider->sendImage($account, $to, (string) $message->media_url, $message->content),
            MessageType::Video => $provider->sendVideo($account, $to, (string) $message->media_url, $message->content),
            MessageType::Audio => $provider->sendAudio($account, $to, (string) $message->media_url),
            MessageType::Document => $provider->sendDocument($account, $to, (string) $message->media_url, basename((string) $message->media_url), $message->content),
            MessageType::Interactive => $provider->sendInteractive($account, $to, $message->payload['interactive'] ?? []),
            MessageType::Template => $provider->sendTemplate(
                $account,
                $to,
                $message->payload['template']['name'] ?? (string) $message->template_name,
                $message->payload['template']['language'] ?? 'en',
                $message->payload['template']['components'] ?? [],
            ),
            default => ProviderResult::failed('unsupported', 'Unsupported outbound message type.'),
        };

        if ($result->success) {
            $message->forceFill([
                'provider_message_id' => $result->providerMessageId,
                'status' => MessageStatus::Sent,
                'sent_at' => now(),
            ])->save();
        } else {
            $this->markFailed($message, $result->errorCode ?? 'unknown', $result->errorMessage ?? 'Unknown error');

            return;
        }

        broadcast(new MessageStatusChanged($message->workspace_id, [
            'message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'status' => $message->status->value,
        ]));
    }

    public function markFailed(Message $message, string $code, string $error): void
    {
        $message->forceFill([
            'status' => MessageStatus::Failed,
            'error_code' => $code,
            'error_message' => $error,
            'failed_at' => now(),
        ])->save();

        broadcast(new MessageStatusChanged($message->workspace_id, [
            'message_id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'status' => MessageStatus::Failed->value,
            'error_message' => $error,
        ]));
    }
}
