<?php

namespace App\Services\Automation\Handlers;

use App\Models\AutomationGoalEvent;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;
use App\Services\Automation\VariableInterpolator;

class GoalNodeHandler implements NodeHandlerInterface
{
    public function __construct(protected VariableInterpolator $interpolator) {}

    public function handle(AutomationContext $context, array $node): NodeResult
    {
        $config = $node['config'] ?? [];
        $name = trim((string) ($config['goal_name'] ?? $config['name'] ?? 'Conversion'));
        if ($name === '') {
            return NodeResult::fail('Goal node has no goal name configured.');
        }

        $rawValue = trim((string) ($config['value'] ?? ''));
        $value = null;
        if ($rawValue !== '') {
            $rendered = $this->interpolator->interpolate($rawValue, $context->resolver());
            if (! is_numeric($rendered)) {
                return NodeResult::fail('Goal value must resolve to a number.');
            }
            $value = (float) $rendered;
        }

        $currency = strtoupper(trim((string) ($config['currency'] ?? $context->workspace->currency ?? '')));
        if ($currency === '') {
            $currency = null;
        }

        $goal = AutomationGoalEvent::query()->updateOrCreate(
            [
                'automation_run_id' => $context->run->id,
                'node_id' => (string) $node['id'],
            ],
            [
                'workspace_id' => $context->workspace->id,
                'automation_id' => $context->automation->id,
                'contact_id' => $context->contact?->id,
                'goal_name' => $name,
                'value' => $value,
                'currency' => $currency,
                'metadata' => [
                    'conversation_id' => $context->conversation?->id,
                ],
            ],
        );

        return NodeResult::next('next', [
            'goal_event_id' => $goal->id,
            'goal_name' => $name,
            'value' => $value,
            'currency' => $currency,
        ]);
    }
}
