<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\Workspace;
use App\Services\Automation\AutomationEventDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ProcessAutomationEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $workspaceId,
        public readonly string $eventType,
        public readonly ?int $contactId = null,
        public readonly array $payload = [],
    ) {
        $this->onQueue('automations');
    }

    public function handle(AutomationEventDispatcher $dispatcher): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);
        if (! $workspace) {
            return;
        }

        $contact = $this->contactId
            ? Contact::query()->where('workspace_id', $workspace->id)->find($this->contactId)
            : null;

        if ($this->contactId && ! $contact) {
            return;
        }

        $subject = (string) (
            $this->payload['tag_id']
            ?? $this->payload['field_key']
            ?? $this->payload['webhook_key']
            ?? 'global'
        );
        $family = match ($this->eventType) {
            'trigger_tag_added', 'trigger_tag_removed' => 'tag_mutation',
            'trigger_field_changed' => 'field_mutation',
            default => $this->eventType,
        };
        $guardKey = implode(':', [
            'automation-event',
            $workspace->id,
            $contact?->id ?? 0,
            $family,
            hash('sha256', $subject),
        ]);

        // Protect against flows that mutate the same contact data that triggered
        // them (e.g. tag A added -> remove/add A forever). Legitimate later
        // mutations remain eligible once this short execution window closes.
        if (! Cache::add($guardKey, true, now()->addSeconds(10))) {
            return;
        }

        try {
            $dispatcher->dispatch($workspace, $this->eventType, $contact, $this->payload);
        } finally {
            // Keep the guard until TTL rather than deleting it immediately; any
            // queued mutation created by this run is intentionally suppressed.
        }
    }
}
