<?php

namespace App\Services\Automation\Handlers;

use App\Jobs\ResumeAutomationWait;
use App\Models\AutomationWait;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;

/**
 * Persists a delay wait and schedules a queued resume. Never sleeps.
 */
class DelayNodeHandler implements NodeHandlerInterface
{
    public function handle(AutomationContext $context, array $node): NodeResult
    {
        $config = $node['config'] ?? [];
        $amount = max(1, (int) ($config['amount'] ?? 1));
        $unit = $config['unit'] ?? 'minutes';

        $resumeAt = match ($unit) {
            'seconds' => now()->addSeconds($amount),
            'hours' => now()->addHours($amount),
            'days' => now()->addDays($amount),
            default => now()->addMinutes($amount),
        };

        $wait = AutomationWait::query()->create([
            'workspace_id' => $context->workspace->id,
            'automation_run_id' => $context->run->id,
            'conversation_id' => $context->conversation?->id,
            'node_id' => $node['id'],
            'wait_type' => AutomationWait::TYPE_DELAY,
            'config' => ['amount' => $amount, 'unit' => $unit],
            'resume_at' => $resumeAt,
            'status' => 'pending',
        ]);

        ResumeAutomationWait::dispatch($wait->id)
            ->onQueue('automations')
            ->delay($resumeAt);

        return NodeResult::wait(['resume_at' => $resumeAt->toIso8601String()]);
    }
}
