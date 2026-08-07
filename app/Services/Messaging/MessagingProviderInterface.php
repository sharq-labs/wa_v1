<?php

namespace App\Services\Messaging;

use App\Models\WhatsAppAccount;

/**
 * Channel-agnostic messaging provider contract.
 *
 * The Automation Engine and Inbox depend only on this interface; they never
 * know how the Meta Graph API (or any future channel) works internally.
 */
interface MessagingProviderInterface
{
    /** Send a plain text message. */
    public function sendText(WhatsAppAccount $account, string $to, string $text, array $options = []): ProviderResult;

    /** Send an approved template message with resolved parameter values. */
    public function sendTemplate(WhatsAppAccount $account, string $to, string $templateName, string $language, array $components = [], array $options = []): ProviderResult;

    public function sendImage(WhatsAppAccount $account, string $to, string $url, ?string $caption = null, array $options = []): ProviderResult;

    public function sendVideo(WhatsAppAccount $account, string $to, string $url, ?string $caption = null, array $options = []): ProviderResult;

    public function sendAudio(WhatsAppAccount $account, string $to, string $url, array $options = []): ProviderResult;

    public function sendDocument(WhatsAppAccount $account, string $to, string $url, ?string $filename = null, ?string $caption = null, array $options = []): ProviderResult;

    /**
     * Send an interactive message (reply buttons or list).
     *
     * @param  array  $interactive  provider-agnostic structure:
     *                              ['type' => 'button'|'list', 'body' => string, 'buttons' => [['id','title']], 'sections' => [...]]
     */
    public function sendInteractive(WhatsAppAccount $account, string $to, array $interactive, array $options = []): ProviderResult;

    /** Mark an inbound message as read. */
    public function markAsRead(WhatsAppAccount $account, string $providerMessageId): bool;

    /** Fetch templates from the provider (raw provider-normalised array). */
    public function getTemplates(WhatsAppAccount $account): array;

    /** Sync provider templates into the local whatsapp_templates table. */
    public function syncTemplates(WhatsAppAccount $account): int;
}
