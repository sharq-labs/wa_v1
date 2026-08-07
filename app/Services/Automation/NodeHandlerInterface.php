<?php

namespace App\Services\Automation;

/**
 * One handler per node type. Handlers must be side-effect complete and fast;
 * anything slow (HTTP, provider sends) still runs inside the queued engine job.
 */
interface NodeHandlerInterface
{
    /**
     * @param  array  $node  the node definition: ['id' => ..., 'type' => ..., 'config' => [...]]
     */
    public function handle(AutomationContext $context, array $node): NodeResult;
}
