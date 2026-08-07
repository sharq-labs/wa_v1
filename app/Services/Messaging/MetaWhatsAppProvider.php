<?php

namespace App\Services\Messaging;

use App\Enums\TemplateStatus;
use App\Models\WhatsAppAccount;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MetaWhatsAppProvider implements MessagingProviderInterface
{
    protected function graphUrl(string $path): string
    {
        $base = rtrim(config('meta.graph_base_url'), '/');
        $version = config('meta.graph_api_version');

        return "{$base}/{$version}/".ltrim($path, '/');
    }

    protected function request(WhatsAppAccount $account)
    {
        return Http::withToken($account->access_token)
            ->connectTimeout(5)
            ->timeout(15)
            ->retry(2, 250, throw: false)
            ->acceptJson();
    }

    protected function sendPayload(WhatsAppAccount $account, array $payload): ProviderResult
    {
        try {
            $response = $this->request($account)->post(
                $this->graphUrl("{$account->phone_number_id}/messages"),
                $payload,
            );

            return $this->toResult($response);
        } catch (\Throwable $e) {
            Log::warning('Meta send failed', ['error' => $e->getMessage(), 'account' => $account->id]);

            return ProviderResult::failed('network_error', $e->getMessage());
        }
    }

    protected function toResult(Response $response): ProviderResult
    {
        $json = $response->json();

        if ($response->successful() && isset($json['messages'][0]['id'])) {
            return ProviderResult::ok($json['messages'][0]['id'], $json);
        }

        return ProviderResult::failed(
            (string) ($json['error']['code'] ?? $response->status()),
            (string) ($json['error']['message'] ?? 'Unknown Graph API error'),
            $json ?? [],
        );
    }

    public function sendText(WhatsAppAccount $account, string $to, string $text, array $options = []): ProviderResult
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => (bool) ($options['preview_url'] ?? false), 'body' => $text],
        ];

        if (! empty($options['reply_to'])) {
            $payload['context'] = ['message_id' => $options['reply_to']];
        }

        return $this->sendPayload($account, $payload);
    }

    public function sendTemplate(WhatsAppAccount $account, string $to, string $templateName, string $language, array $components = [], array $options = []): ProviderResult
    {
        return $this->sendPayload($account, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
                'components' => $components,
            ],
        ]);
    }

    public function sendImage(WhatsAppAccount $account, string $to, string $url, ?string $caption = null, array $options = []): ProviderResult
    {
        return $this->sendPayload($account, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'image',
            'image' => array_filter(['link' => $url, 'caption' => $caption]),
        ]);
    }

    public function sendVideo(WhatsAppAccount $account, string $to, string $url, ?string $caption = null, array $options = []): ProviderResult
    {
        return $this->sendPayload($account, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'video',
            'video' => array_filter(['link' => $url, 'caption' => $caption]),
        ]);
    }

    public function sendAudio(WhatsAppAccount $account, string $to, string $url, array $options = []): ProviderResult
    {
        return $this->sendPayload($account, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'audio',
            'audio' => ['link' => $url],
        ]);
    }

    public function sendDocument(WhatsAppAccount $account, string $to, string $url, ?string $filename = null, ?string $caption = null, array $options = []): ProviderResult
    {
        return $this->sendPayload($account, [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'document',
            'document' => array_filter(['link' => $url, 'filename' => $filename, 'caption' => $caption]),
        ]);
    }

    public function sendInteractive(WhatsAppAccount $account, string $to, array $interactive, array $options = []): ProviderResult
    {
        $type = $interactive['type'] ?? 'button';
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
        ];

        if ($type === 'button') {
            $payload['interactive'] = [
                'type' => 'button',
                'body' => ['text' => $interactive['body'] ?? ''],
                'action' => [
                    'buttons' => array_map(fn (array $b) => [
                        'type' => 'reply',
                        'reply' => ['id' => $b['id'], 'title' => mb_substr($b['title'], 0, 20)],
                    ], $interactive['buttons'] ?? []),
                ],
            ];
        } else {
            $payload['interactive'] = [
                'type' => 'list',
                'body' => ['text' => $interactive['body'] ?? ''],
                'action' => [
                    'button' => mb_substr($interactive['button'] ?? 'Select', 0, 20),
                    'sections' => $interactive['sections'] ?? [],
                ],
            ];
        }

        if (! empty($interactive['header'])) {
            $payload['interactive']['header'] = ['type' => 'text', 'text' => $interactive['header']];
        }
        if (! empty($interactive['footer'])) {
            $payload['interactive']['footer'] = ['text' => $interactive['footer']];
        }

        return $this->sendPayload($account, $payload);
    }

    public function markAsRead(WhatsAppAccount $account, string $providerMessageId): bool
    {
        try {
            return $this->request($account)->post(
                $this->graphUrl("{$account->phone_number_id}/messages"),
                [
                    'messaging_product' => 'whatsapp',
                    'status' => 'read',
                    'message_id' => $providerMessageId,
                ],
            )->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function createTemplate(WhatsAppAccount $account, array $definition): array
    {
        $components = [];
        $headerType = strtolower((string) ($definition['header_type'] ?? ''));
        $headerContent = trim((string) ($definition['header_content'] ?? ''));

        if ($headerType === 'text' && $headerContent !== '') {
            $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $headerContent];
        } elseif (in_array($headerType, ['image', 'video', 'document'], true)) {
            // Media template headers require an uploaded example handle. Until a
            // handle is supplied by the UI/provider upload flow, fail explicitly
            // instead of creating a local-only template that Meta never received.
            throw new RuntimeException('Media template headers require a Meta example media handle.');
        }

        $body = ['type' => 'BODY', 'text' => (string) $definition['body']];
        if (! empty($definition['variables'])) {
            ksort($definition['variables']);
            $body['example'] = ['body_text' => [array_values($definition['variables'])]];
        }
        $components[] = $body;

        if (! empty($definition['footer'])) {
            $components[] = ['type' => 'FOOTER', 'text' => (string) $definition['footer']];
        }

        if (! empty($definition['buttons'])) {
            $buttons = [];
            foreach ($definition['buttons'] as $button) {
                $type = strtolower((string) ($button['type'] ?? ''));
                $mapped = match ($type) {
                    'quick_reply' => ['type' => 'QUICK_REPLY', 'text' => $button['text']],
                    'url' => ['type' => 'URL', 'text' => $button['text'], 'url' => $button['url'] ?? ''],
                    'phone' => ['type' => 'PHONE_NUMBER', 'text' => $button['text'], 'phone_number' => $button['phone'] ?? ''],
                    default => null,
                };
                if ($mapped) {
                    $buttons[] = $mapped;
                }
            }
            if ($buttons !== []) {
                $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
            }
        }

        $response = $this->request($account)->post(
            $this->graphUrl("{$account->waba_id}/message_templates"),
            [
                'name' => $definition['name'],
                'language' => $definition['language'],
                'category' => $definition['category'],
                'components' => $components,
            ],
        );

        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('error.message') ?? 'Meta template creation failed.'));
        }

        return $response->json() ?? [];
    }

    public function deleteTemplate(WhatsAppAccount $account, string $templateName): bool
    {
        try {
            return $this->request($account)->delete(
                $this->graphUrl("{$account->waba_id}/message_templates"),
                ['name' => $templateName],
            )->successful();
        } catch (\Throwable $e) {
            Log::warning('Meta template delete failed', ['account' => $account->id, 'error' => $e->getMessage()]);
            return false;
        }
    }

    public function getTemplates(WhatsAppAccount $account): array
    {
        $templates = [];
        $url = $this->graphUrl("{$account->waba_id}/message_templates?limit=100");

        while ($url) {
            $response = $this->request($account)->get($url);
            if (! $response->successful()) {
                break;
            }

            $json = $response->json();
            $templates = array_merge($templates, $json['data'] ?? []);
            $url = $json['paging']['next'] ?? null;
        }

        return $templates;
    }

    public function syncTemplates(WhatsAppAccount $account): int
    {
        $remote = $this->getTemplates($account);
        $count = 0;

        foreach ($remote as $item) {
            $components = collect($item['components'] ?? []);
            $header = $components->firstWhere('type', 'HEADER');
            $body = $components->firstWhere('type', 'BODY');
            $footer = $components->firstWhere('type', 'FOOTER');
            $buttons = $components->firstWhere('type', 'BUTTONS');

            $account->templates()->updateOrCreate(
                ['name' => $item['name'], 'language' => $item['language']],
                [
                    'workspace_id' => $account->workspace_id,
                    'meta_template_id' => $item['id'] ?? null,
                    'category' => $item['category'] ?? 'MARKETING',
                    'status' => $this->mapTemplateStatus($item['status'] ?? 'PENDING'),
                    'header_type' => $header ? strtolower($header['format'] ?? 'text') : null,
                    'header_content' => $header['text'] ?? null,
                    'body' => $body['text'] ?? '',
                    'footer' => $footer['text'] ?? null,
                    'buttons' => $buttons['buttons'] ?? null,
                    'rejection_reason' => $item['rejected_reason'] ?? null,
                    'quality_rating' => $item['quality_score']['score'] ?? null,
                    'last_synced_at' => now(),
                ],
            );
            $count++;
        }

        $account->update(['last_sync_at' => now()]);

        return $count;
    }

    protected function mapTemplateStatus(string $metaStatus): TemplateStatus
    {
        return match (strtoupper($metaStatus)) {
            'APPROVED' => TemplateStatus::Approved,
            'REJECTED' => TemplateStatus::Rejected,
            'PAUSED' => TemplateStatus::Paused,
            'DISABLED' => TemplateStatus::Disabled,
            default => TemplateStatus::Pending,
        };
    }
}
