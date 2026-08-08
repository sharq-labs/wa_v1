<?php

namespace App\Services\Messaging;

use App\Models\WhatsAppAccount;

interface MessagingProviderInterface
{
    public function sendText(WhatsAppAccount $account, string $to, string $text, array $options = []): ProviderResult;

    public function sendTemplate(WhatsAppAccount $account, string $to, string $templateName, string $language, array $components = [], array $options = []): ProviderResult;

    public function sendImage(WhatsAppAccount $account, string $to, string $url, ?string $caption = null, array $options = []): ProviderResult;

    public function sendVideo(WhatsAppAccount $account, string $to, string $url, ?string $caption = null, array $options = []): ProviderResult;

    public function sendAudio(WhatsAppAccount $account, string $to, string $url, array $options = []): ProviderResult;

    public function sendDocument(WhatsAppAccount $account, string $to, string $url, ?string $filename = null, ?string $caption = null, array $options = []): ProviderResult;

    public function sendInteractive(WhatsAppAccount $account, string $to, array $interactive, array $options = []): ProviderResult;

    public function markAsRead(WhatsAppAccount $account, string $providerMessageId): bool;

    /** Create a provider-side message template and return provider metadata. */
    public function createTemplate(WhatsAppAccount $account, array $definition): array;

    /** Delete a provider-side message template. */
    public function deleteTemplate(WhatsAppAccount $account, string $templateName): bool;

    public function getTemplates(WhatsAppAccount $account): array;

    public function syncTemplates(WhatsAppAccount $account): int;
}
