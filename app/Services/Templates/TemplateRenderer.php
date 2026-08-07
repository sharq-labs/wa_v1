<?php

namespace App\Services\Templates;

use App\Models\Conversation;
use App\Models\WhatsAppTemplate;
use App\Services\Automation\AutomationContext;

/**
 * Resolves template variable mappings ({{1}}, {{2}}, ...) into Meta component
 * parameters plus a rendered human-readable preview text.
 *
 * Mapping entry shape:
 *   {"index": 1, "source": "static|contact|custom|variable|workspace|agent", "value": "..."}
 * For non-static sources, "value" is the key/path (e.g. "first_name", "order_number").
 */
class TemplateRenderer
{
    public function render(WhatsAppTemplate $template, ?Conversation $conversation, array $mappings, ?AutomationContext $ctx = null): array
    {
        $values = [];
        $count = $template->bodyVariableCount();

        for ($i = 1; $i <= $count; $i++) {
            $mapping = collect($mappings)->first(fn ($m) => (int) ($m['index'] ?? 0) === $i);
            $values[$i] = $this->resolveMapping($mapping, $conversation, $ctx) ?? '';
        }

        $text = preg_replace_callback(
            '/\{\{(\d+)\}\}/',
            fn ($m) => $values[(int) $m[1]] ?? '',
            $template->body,
        );

        $components = [];

        if ($values !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($v) => ['type' => 'text', 'text' => (string) $v],
                    array_values($values),
                ),
            ];
        }

        if (in_array($template->header_type, ['image', 'video', 'document'], true) && $template->header_content) {
            $components[] = [
                'type' => 'header',
                'parameters' => [[
                    'type' => $template->header_type,
                    $template->header_type => ['link' => $template->header_content],
                ]],
            ];
        }

        return [
            'components' => $components,
            'text' => $text,
            'values' => $values,
        ];
    }

    protected function resolveMapping(?array $mapping, ?Conversation $conversation, ?AutomationContext $ctx): ?string
    {
        if (! $mapping) {
            return null;
        }

        $source = $mapping['source'] ?? 'static';
        $value = (string) ($mapping['value'] ?? '');

        if ($source === 'static') {
            return $value;
        }

        if ($ctx) {
            return $ctx->resolve($this->pathFor($source, $value));
        }

        $contact = $conversation?->contact;
        $workspace = $conversation?->workspace;

        return match ($source) {
            'contact' => match ($value) {
                'first_name' => $contact?->first_name ?? $contact?->display_name,
                'last_name' => $contact?->last_name,
                'name', 'full_name' => $contact?->full_name,
                'phone', 'phone_number' => $contact?->phone_number,
                'email' => $contact?->email,
                default => null,
            },
            'custom' => $contact?->customFieldValue($value),
            'workspace' => $value === 'name' ? $workspace?->name : null,
            'agent' => $value === 'name' ? $conversation?->assignedUser?->name : null,
            default => null,
        };
    }

    protected function pathFor(string $source, string $value): string
    {
        return match ($source) {
            'variable', 'variables' => 'variables.'.$value,
            default => $source.'.'.$value,
        };
    }
}
