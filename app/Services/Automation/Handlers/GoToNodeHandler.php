<?php

namespace App\Services\Automation\Handlers;

use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;

class GoToNodeHandler implements NodeHandlerInterface
{
    public function handle(AutomationContext $context, array $node): NodeResult
    {
        $target = ($node['config'] ?? [])['target_node_id'] ?? null;

        if (! $target) {
            return NodeResult::fail('Go To node has no target node selected.');
        }

        return NodeResult::goto($target);
    }
}
