<?php

namespace App\Services\Automation\Handlers;

use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;

class StopNodeHandler implements NodeHandlerInterface
{
    public function handle(AutomationContext $context, array $node): NodeResult
    {
        return NodeResult::stop();
    }
}
