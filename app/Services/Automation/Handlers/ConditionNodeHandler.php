<?php

namespace App\Services\Automation\Handlers;

use App\Services\Automation\AutomationContext;
use App\Services\Automation\ConditionEvaluator;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;

class ConditionNodeHandler implements NodeHandlerInterface
{
    public function __construct(protected ConditionEvaluator $evaluator) {}

    public function handle(AutomationContext $context, array $node): NodeResult
    {
        $result = $this->evaluator->evaluate($context, $node['config'] ?? []);

        return NodeResult::next($result ? 'true' : 'false', ['result' => $result]);
    }
}
