<?php

namespace App\Services\Automation;

use App\Enums\AutomationState;
use App\Models\Automation;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Workspace;
use Illuminate\Support\Collection;

class AutomationEventDispatcher
{
    public function __construct(protected AutomationEngine $engine) {}

    public function dispatch(Workspace $workspace, string $eventType, ?Contact $contact, array $payload = []): int
    {
        $matches = $this->matches($workspace, $eventType, $payload);
        if ($matches->isEmpty()) {
            return 0;
        }

        $conversation = $contact ? $this->resolveConversation($workspace, $contact) : null;
        $allowMultiple = isset($payload['automation_id'])
            ? false
            : (bool) $workspace->setting('automation.allow_multiple', false);
        $started = 0;

        foreach ($allowMultiple ? $matches : $matches->take(1) as $automation) {
            if ($this->engine->start($automation, $contact, $conversation) !== null) {
                $started++;
            }
        }

        return $started;
    }

    /** @return Collection<int, Automation> */
    public function matches(Workspace $workspace, string $eventType, array $payload = []): Collection
    {
        $targetAutomationId = isset($payload['automation_id']) ? (int) $payload['automation_id'] : null;

        return Automation::query()
            ->forWorkspace($workspace)
            ->where('status', AutomationState::Published)
            ->whereNotNull('published_version_id')
            ->when($targetAutomationId, fn ($query) => $query->whereKey($targetAutomationId))
            ->with('publishedVersion')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get()
            ->filter(function (Automation $automation) use ($eventType, $payload) {
                $trigger = $this->triggerNode($automation);

                return $trigger !== null
                    && ($trigger['type'] ?? null) === $eventType
                    && $this->configMatches($eventType, $trigger['config'] ?? [], $payload);
            })
            ->values();
    }

    protected function triggerNode(Automation $automation): ?array
    {
        foreach ($automation->publishedVersion?->definitionNodes() ?? [] as $node) {
            if (str_starts_with((string) ($node['type'] ?? ''), 'trigger_')) {
                return $node;
            }
        }

        return null;
    }

    protected function configMatches(string $eventType, array $config, array $payload): bool
    {
        return match ($eventType) {
            'trigger_tag_added', 'trigger_tag_removed' => $this->tagMatches($config, $payload),
            'trigger_field_changed' => $this->fieldMatches($config, $payload),
            'trigger_webhook', 'trigger_scheduled' => true,
            default => true,
        };
    }

    protected function tagMatches(array $config, array $payload): bool
    {
        if (! empty($config['tag_id'])) {
            return (int) $config['tag_id'] === (int) ($payload['tag_id'] ?? 0);
        }

        if (! empty($config['tag_name'])) {
            return mb_strtolower(trim((string) $config['tag_name']))
                === mb_strtolower(trim((string) ($payload['tag_name'] ?? '')));
        }

        return true;
    }

    protected function fieldMatches(array $config, array $payload): bool
    {
        $configured = trim((string) ($config['field_key'] ?? $config['key'] ?? ''));
        if ($configured !== '' && $configured !== (string) ($payload['field_key'] ?? '')) {
            return false;
        }

        $mode = (string) ($config['change_type'] ?? 'any');
        $old = $payload['old_value'] ?? null;
        $new = $payload['new_value'] ?? null;

        return match ($mode) {
            'became_empty' => ($new === null || $new === '') && ! ($old === null || $old === ''),
            'became_non_empty' => ! ($new === null || $new === '') && ($old === null || $old === ''),
            default => $old !== $new,
        };
    }

    protected function resolveConversation(Workspace $workspace, Contact $contact): ?Conversation
    {
        return Conversation::query()
            ->where('workspace_id', $workspace->id)
            ->where('contact_id', $contact->id)
            ->latest('last_message_at')
            ->latest('id')
            ->first();
    }
}
