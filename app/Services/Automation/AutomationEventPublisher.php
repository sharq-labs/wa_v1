<?php

namespace App\Services\Automation;

use App\Enums\AutomationState;
use App\Jobs\ProcessAutomationEvent;
use App\Models\Automation;
use App\Models\Workspace;

class AutomationEventPublisher
{
    public function publish(
        int $workspaceId,
        string $eventType,
        ?int $contactId = null,
        array $payload = [],
    ): bool {
        if (! $this->hasMatchingTrigger($workspaceId, $eventType, $payload)) {
            return false;
        }

        ProcessAutomationEvent::dispatch(
            $workspaceId,
            $eventType,
            $contactId,
            $payload,
        )->afterCommit();

        return true;
    }

    public function hasMatchingTrigger(int $workspaceId, string $eventType, array $payload = []): bool
    {
        $workspace = Workspace::query()->find($workspaceId);
        if (! $workspace) {
            return false;
        }

        // Reuse the exact same matcher used when the queued event executes so
        // model-level mutations do not add useless queue traffic or surprise
        // existing workflows/tests when no published listener exists.
        return app(AutomationEventDispatcher::class)
            ->matches($workspace, $eventType, $payload)
            ->isNotEmpty();
    }
}
