<?php

namespace App\Services\Automation\Handlers;

use App\Models\Automation;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;

class StartAutomationNodeHandler implements NodeHandlerInterface
{
    public function handle(AutomationContext $context, array $node): NodeResult
    {
        $config = $node['config'] ?? [];
        $automationId = $config['automation_id'] ?? null;

        if (! $automationId) {
            return NodeResult::fail('Start Automation node has no automation selected.');
        }

        if ((int) $automationId === $context->automation->id) {
            return NodeResult::fail('An automation cannot start itself.');
        }

        $target = Automation::query()
            ->forWorkspace($context->workspace)
            ->find($automationId);

        if (! $target || ! $target->isPublished()) {
            return NodeResult::fail('Target automation is not published.');
        }

        /** @var AutomationEngine $engine */
        $engine = app(AutomationEngine::class);

        $childRun = $engine->start(
            $target,
            $context->contact,
            $context->conversation,
            $context->triggerMessage,
            $context->run,
        );

        if (! $childRun) {
            return NodeResult::fail('Child automation could not start (depth or plan limit).');
        }

        $stopParent = ! empty($config['stop_parent']);

        return $stopParent
            ? NodeResult::stop(['child_run' => $childRun->uuid])
            : NodeResult::next('next', ['child_run' => $childRun->uuid]);
    }
}
