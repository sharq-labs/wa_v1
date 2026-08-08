<?php

namespace App\Services\Automation\Handlers;

use App\Enums\NodeType;
use App\Events\ContactUpdated;
use App\Jobs\ProcessAutomationEvent;
use App\Models\Tag;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;

/**
 * Handles Add Tag and Remove Tag. Idempotent: adding an existing tag or
 * removing a missing one is a no-op.
 */
class TagNodeHandler implements NodeHandlerInterface
{
    public function handle(AutomationContext $context, array $node): NodeResult
    {
        $config = $node['config'] ?? [];

        if (! $context->contact) {
            return NodeResult::fail('No contact in context.');
        }

        $tag = null;

        if (! empty($config['tag_id'])) {
            $tag = Tag::query()->forWorkspace($context->workspace)->find($config['tag_id']);
        } elseif (! empty($config['tag_name'])) {
            $tag = Tag::query()->forWorkspace($context->workspace)
                ->where('name', $config['tag_name'])
                ->first();

            if (! $tag && $node['type'] === NodeType::AddTag->value) {
                $tag = Tag::query()->create([
                    'workspace_id' => $context->workspace->id,
                    'name' => $config['tag_name'],
                ]);
            }
        }

        if (! $tag) {
            return NodeResult::fail('Tag node has no valid tag configured.');
        }

        $changed = false;
        if ($node['type'] === NodeType::AddTag->value) {
            $changes = $context->contact->tags()->syncWithoutDetaching([$tag->id]);
            $action = 'added';
            $changed = ($changes['attached'] ?? []) !== [];
            $eventType = NodeType::TriggerTagAdded->value;
        } else {
            $changed = $context->contact->tags()->detach($tag->id) > 0;
            $action = 'removed';
            $eventType = NodeType::TriggerTagRemoved->value;
        }

        broadcast(new ContactUpdated($context->workspace->id, ['contact_id' => $context->contact->id]));

        if ($changed) {
            ProcessAutomationEvent::dispatch(
                $context->workspace->id,
                $eventType,
                $context->contact->id,
                [
                    'tag_id' => $tag->id,
                    'tag_name' => $tag->name,
                    'source' => 'automation',
                    'source_run_id' => $context->run->id,
                ],
            )->afterCommit();
        }

        return NodeResult::next('next', [
            'tag' => $tag->name,
            'action' => $action,
            'changed' => $changed,
        ]);
    }
}
