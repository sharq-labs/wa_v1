<?php

namespace App\Services\Messaging;

use App\Enums\AutomationStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Events\ContactUpdated;
use App\Events\ConversationUpdated;
use App\Events\NewMessage;
use App\Http\Resources\MessageResource;
use App\Jobs\ProcessInboundMessageAutomation;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Normalised inbound message ingestion. Idempotent on provider_message_id. */
class InboundMessageService
{
    public function ingest(WhatsAppAccount $account, array $data): ?Message
    {
        $existing = Message::query()
            ->where('whatsapp_account_id', $account->id)
            ->where('provider_message_id', $data['provider_message_id'])
            ->first();

        if ($existing) {
            return null;
        }

        try {
            [$message, $contact, $conversation, $isNewContact, $consentChanged] = DB::transaction(function () use ($account, $data) {
                $contact = $this->resolveContact($account, $data);
                $isNewContact = $contact->wasRecentlyCreated;
                $consentChanged = $this->applyConsentKeyword($contact, $data);
                $conversation = $this->resolveConversation($account, $contact);

                $message = Message::query()->create([
                    'workspace_id' => $account->workspace_id,
                    'conversation_id' => $conversation->id,
                    'contact_id' => $contact->id,
                    'whatsapp_account_id' => $account->id,
                    'provider_message_id' => $data['provider_message_id'],
                    'direction' => MessageDirection::Inbound,
                    'sender_type' => MessageSenderType::Contact,
                    'message_type' => MessageType::tryFrom($data['type'] ?? 'text') ?? MessageType::Unknown,
                    'content' => $data['text'] ?? null,
                    'media_url' => $data['media_url'] ?? null,
                    'media_mime_type' => $data['media_mime_type'] ?? null,
                    'payload' => $data['payload'] ?? null,
                    'status' => MessageStatus::Received,
                ]);

                $now = now();
                $wasClosed = $conversation->status === ConversationStatus::Closed;
                $conversation->forceFill([
                    'status' => $wasClosed ? ConversationStatus::Open : $conversation->status,
                    'last_message_at' => $now,
                    'last_inbound_at' => $now,
                    'unread_count' => $conversation->unread_count + 1,
                    'opened_at' => $conversation->opened_at ?? $now,
                    'closed_at' => $wasClosed ? null : $conversation->closed_at,
                ])->save();

                $contact->forceFill([
                    'last_seen_at' => $now,
                    'last_message_at' => $now,
                ])->save();

                return [$message, $contact, $conversation, $isNewContact, $consentChanged];
            });
        } catch (UniqueConstraintViolationException) {
            // Database uniqueness is the final line of defence when two workers
            // race past the optimistic lookup above.
            return null;
        }

        if ($consentChanged) {
            broadcast(new ContactUpdated($account->workspace_id, [
                'contact_id' => $contact->id,
                'opt_in_status' => $contact->opt_in_status,
            ]));
        }

        broadcast(new NewMessage($account->workspace_id, [
            'message' => MessageResource::make($message)->resolve(),
            'conversation_id' => $conversation->id,
        ]));

        broadcast(new ConversationUpdated($account->workspace_id, [
            'conversation_id' => $conversation->id,
            'status' => $conversation->status->value,
            'unread_count' => $conversation->unread_count,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
        ]));

        ProcessInboundMessageAutomation::dispatch($message->id, $isNewContact)->onQueue('automations');

        return $message;
    }

    protected function applyConsentKeyword(Contact $contact, array $data): bool
    {
        if (($data['type'] ?? null) !== 'text' || ! is_string($data['text'] ?? null)) {
            return false;
        }

        $keyword = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $data['text'])));
        $optIn = config('whatsapp.opt_in_keywords', []);
        $optOut = config('whatsapp.opt_out_keywords', []);

        if (in_array($keyword, $optOut, true)) {
            $changed = $contact->opt_in_status !== 'opted_out';
            $contact->forceFill([
                'opt_in_status' => 'opted_out',
                'opt_out_at' => now(),
                'consent_source' => 'whatsapp_keyword',
            ])->save();

            return $changed;
        }

        if (in_array($keyword, $optIn, true)) {
            $changed = $contact->opt_in_status !== 'opted_in';
            $contact->forceFill([
                'opt_in_status' => 'opted_in',
                'opt_in_at' => now(),
                'opt_out_at' => null,
                'consent_source' => 'whatsapp_keyword',
            ])->save();

            return $changed;
        }

        return false;
    }

    protected function resolveContact(WhatsAppAccount $account, array $data): Contact
    {
        $phone = $data['phone_number'] ?? $data['wa_id'];

        $contact = Contact::query()
            ->where('workspace_id', $account->workspace_id)
            ->where(function ($q) use ($data, $phone) {
                $q->where('wa_id', $data['wa_id'])->orWhere('phone_number', $phone);
            })->first();

        if ($contact) {
            if (! $contact->wa_id) {
                $contact->forceFill(['wa_id' => $data['wa_id']])->save();
            }

            return $contact;
        }

        return Contact::query()->create([
            'workspace_id' => $account->workspace_id,
            'whatsapp_account_id' => $account->id,
            'wa_id' => $data['wa_id'],
            'phone_number' => $phone,
            'display_name' => $data['profile_name'] ?? null,
            'first_name' => $data['profile_name'] ? explode(' ', trim($data['profile_name']))[0] : null,
        ]);
    }

    protected function resolveConversation(WhatsAppAccount $account, Contact $contact): Conversation
    {
        return Conversation::query()->firstOrCreate(
            [
                'workspace_id' => $account->workspace_id,
                'whatsapp_account_id' => $account->id,
                'contact_id' => $contact->id,
            ],
            [
                'status' => ConversationStatus::Open,
                'automation_status' => AutomationStatus::Active,
                'opened_at' => now(),
            ],
        );
    }
}
