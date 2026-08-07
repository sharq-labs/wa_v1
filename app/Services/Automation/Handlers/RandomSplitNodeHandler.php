<?php

namespace App\Services\Automation\Handlers;

use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;

/**
 * Splits traffic across weighted branches (A/B testing).
 * Config: {"branches": [{"handle": "a", "weight": 50}, {"handle": "b", "weight": 50}]}
 */
class RandomSplitNodeHandler implements NodeHandlerInterface
{
    public function handle(AutomationContext $context, array $node): NodeResult
    {
        $branches = ($node['config'] ?? [])['branches'] ?? [
            ['handle' => 'a', 'weight' => 50],
            ['handle' => 'b', 'weight' => 50],
        ];

        $total = array_sum(array_map(fn ($b) => max(0, (int) ($b['weight'] ?? 0)), $branches));

        if ($total <= 0) {
            return NodeResult::next($branches[0]['handle'] ?? 'a');
        }

        $roll = random_int(1, $total);
        $cumulative = 0;

        foreach ($branches as $branch) {
            $cumulative += max(0, (int) ($branch['weight'] ?? 0));

            if ($roll <= $cumulative) {
                return NodeResult::next($branch['handle'] ?? 'a', ['roll' => $roll]);
            }
        }

        return NodeResult::next($branches[0]['handle'] ?? 'a', ['roll' => $roll]);
    }
}
