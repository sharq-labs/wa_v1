<?php

namespace App\Services\Automation\Handlers;

use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;
use App\Services\Automation\VariableInterpolator;

class AddNoteNodeHandler implements NodeHandlerInterface
{
    public function __construct(protected VariableInterpolator $interpolator) {}

    public function handle(AutomationContext $context, array $node): NodeResult
    {
        $body = (string) (($node['config'] ?? [])['body'] ?? '');

        if ($body === '') {
            return NodeResult::fail('Internal note node has no text.');
        }

        if (! $context->conversation) {
            return NodeResult::fail('No conversation in context.');
        }

        $rendered = $this->interpolator->interpolate($body, $context->resolver());

        $context->conversation->notes()->create([
            'workspace_id' => $context->workspace->id,
            'user_id' => null, // authored by the bot
            'body' => $rendered,
        ]);

        return NodeResult::next('next', ['note' => $rendered]);
    }
}
