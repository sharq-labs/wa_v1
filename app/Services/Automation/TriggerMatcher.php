<?php

namespace App\Services\Automation;

use App\Enums\AutomationState;
use App\Enums\NodeType;
use App\Models\Automation;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * Finds published automations whose trigger matches an inbound message.
 */
class TriggerMatcher
{
    /**
     * @return Collection<int, Automation> ordered by priority desc
     */
    public function match(Workspace $workspace, Message $message, bool $isNewContact): Collection
    {
        $automations = Automation::query()
            ->forWorkspace($workspace)
            ->where('status', AutomationState::Published)
            ->whereNotNull('published_version_id')
            ->with('publishedVersion')
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();

        return $automations->filter(function (Automation $automation) use ($message, $isNewContact) {
            $trigger = $this->triggerNode($automation);

            return $trigger !== null && $this->triggerMatches($trigger, $message, $isNewContact);
        })->values();
    }

    public function triggerNode(Automation $automation): ?array
    {
        $version = $automation->publishedVersion;

        if (! $version) {
            return null;
        }

        foreach ($version->definitionNodes() as $node) {
            $type = NodeType::tryFrom($node['type'] ?? '');

            if ($type?->isTrigger()) {
                return $node;
            }
        }

        return null;
    }

    public function triggerMatches(array $trigger, Message $message, bool $isNewContact): bool
    {
        $config = $trigger['config'] ?? [];
        $text = mb_strtolower(trim((string) $message->content));

        return match ($trigger['type']) {
            NodeType::TriggerIncomingMessage->value => true,
            NodeType::TriggerNewContact->value => $isNewContact,
            NodeType::TriggerKeyword->value => $this->matchesKeywords($text, $config),
            NodeType::TriggerButtonClick->value => ($message->payload['reply_id'] ?? null) !== null
                && $this->matchesButton($message, $config),
            NodeType::TriggerListSelection->value => ($message->payload['reply_id'] ?? null) !== null,
            default => false,
        };
    }

    protected function matchesKeywords(string $text, array $config): bool
    {
        if ($text === '') {
            return false;
        }

        $keywords = array_filter(array_map(
            fn ($k) => mb_strtolower(trim((string) $k)),
            $config['keywords'] ?? [],
        ));

        if ($keywords === []) {
            return false;
        }

        $mode = $config['match_type'] ?? 'contains';

        foreach ($keywords as $keyword) {
            $matched = match ($mode) {
                'exact' => $text === $keyword,
                'starts_with' => str_starts_with($text, $keyword),
                'ends_with' => str_ends_with($text, $keyword),
                default => str_contains($text, $keyword),
            };

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    protected function matchesButton(Message $message, array $config): bool
    {
        $buttonId = $config['button_id'] ?? null;

        if (! $buttonId) {
            return true;
        }

        return ($message->payload['reply_id'] ?? null) === $buttonId;
    }
}
