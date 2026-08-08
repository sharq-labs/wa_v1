<?php

namespace App\Services\Messaging;

use App\Enums\TemplateStatus;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Str;

class FakeWhatsAppProvider implements MessagingProviderInterface
{
    public static array $sent = [];

    public static bool $failNextSend = false;

    public static function reset(): void
    {
        static::$sent = [];
        static::$failNextSend = false;
    }

    protected function record(WhatsAppAccount $account, string $to, string $type, array $payload): ProviderResult
    {
        if (static::$failNextSend) {
            static::$failNextSend = false;

            return ProviderResult::failed('fake_error', 'Simulated provider failure.');
        }

        $id = 'wamid.fake.'.Str::uuid();
        static::$sent[] = [
            'account_id' => $account->id,
            'to' => $to,
            'type' => $type,
            'payload' => $payload,
            'provider_message_id' => $id,
        ];

        return ProviderResult::ok($id, ['fake' => true]);
    }

    public function sendText(WhatsAppAccount $account, string $to, string $text, array $options = []): ProviderResult
    {
        return $this->record($account, $to, 'text', ['text' => $text] + $options);
    }

    public function sendTemplate(WhatsAppAccount $account, string $to, string $templateName, string $language, array $components = [], array $options = []): ProviderResult
    {
        return $this->record($account, $to, 'template', [
            'template' => $templateName,
            'language' => $language,
            'components' => $components,
        ] + $options);
    }

    public function sendImage(WhatsAppAccount $account, string $to, string $url, ?string $caption = null, array $options = []): ProviderResult
    {
        return $this->record($account, $to, 'image', ['url' => $url, 'caption' => $caption] + $options);
    }

    public function sendVideo(WhatsAppAccount $account, string $to, string $url, ?string $caption = null, array $options = []): ProviderResult
    {
        return $this->record($account, $to, 'video', ['url' => $url, 'caption' => $caption] + $options);
    }

    public function sendAudio(WhatsAppAccount $account, string $to, string $url, array $options = []): ProviderResult
    {
        return $this->record($account, $to, 'audio', ['url' => $url] + $options);
    }

    public function sendDocument(WhatsAppAccount $account, string $to, string $url, ?string $filename = null, ?string $caption = null, array $options = []): ProviderResult
    {
        return $this->record($account, $to, 'document', ['url' => $url, 'filename' => $filename, 'caption' => $caption] + $options);
    }

    public function sendInteractive(WhatsAppAccount $account, string $to, array $interactive, array $options = []): ProviderResult
    {
        return $this->record($account, $to, 'interactive', ['interactive' => $interactive] + $options);
    }

    public function markAsRead(WhatsAppAccount $account, string $providerMessageId): bool
    {
        return true;
    }

    public function createTemplate(WhatsAppAccount $account, array $definition): array
    {
        return [
            'id' => 'fake_'.Str::random(16),
            'status' => 'APPROVED',
            'category' => $definition['category'] ?? 'UTILITY',
        ];
    }

    public function deleteTemplate(WhatsAppAccount $account, string $templateName): bool
    {
        return true;
    }

    public function getTemplates(WhatsAppAccount $account): array
    {
        return $account->templates()->get()->map(fn (WhatsAppTemplate $t) => [
            'id' => $t->meta_template_id ?? 'fake_'.$t->id,
            'name' => $t->name,
            'language' => $t->language,
            'category' => $t->category,
            'status' => 'APPROVED',
            'components' => [],
        ])->all();
    }

    public function syncTemplates(WhatsAppAccount $account): int
    {
        $updated = $account->templates()
            ->whereIn('status', [TemplateStatus::Pending, TemplateStatus::Draft])
            ->get()
            ->each(function (WhatsAppTemplate $template) {
                $template->update([
                    'status' => TemplateStatus::Approved,
                    'meta_template_id' => $template->meta_template_id ?? 'fake_'.Str::random(12),
                    'last_synced_at' => now(),
                ]);
            })->count();

        $account->templates()->update(['last_synced_at' => now()]);
        $account->update(['last_sync_at' => now()]);

        return $updated;
    }
}
