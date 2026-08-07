<?php

namespace App\Services\Automation\Handlers;

use App\Jobs\ResumeAutomationWait;
use App\Models\AutomationWait;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;
use Carbon\Carbon;

/**
 * Waits until a specific moment: tomorrow at a time, a fixed datetime,
 * a datetime stored in a custom field, or the next working hours.
 */
class WaitUntilNodeHandler implements NodeHandlerInterface
{
    public function handle(AutomationContext $context, array $node): NodeResult
    {
        $config = $node['config'] ?? [];
        $tz = $context->workspace->timezone ?: 'UTC';

        $resumeAt = $this->resolveMoment($context, $config, $tz);

        if (! $resumeAt) {
            return NodeResult::fail('Wait Until node could not resolve a target time.');
        }

        if ($resumeAt->isPast()) {
            return NodeResult::next('next', ['skipped' => 'target time already past']);
        }

        $wait = AutomationWait::query()->create([
            'workspace_id' => $context->workspace->id,
            'automation_run_id' => $context->run->id,
            'conversation_id' => $context->conversation?->id,
            'node_id' => $node['id'],
            'wait_type' => AutomationWait::TYPE_UNTIL,
            'config' => $config,
            'resume_at' => $resumeAt->utc(),
            'status' => 'pending',
        ]);

        ResumeAutomationWait::dispatch($wait->id)
            ->onQueue('automations')
            ->delay($wait->resume_at);

        return NodeResult::wait(['resume_at' => $resumeAt->toIso8601String()]);
    }

    protected function resolveMoment(AutomationContext $context, array $config, string $tz): ?Carbon
    {
        $mode = $config['mode'] ?? 'tomorrow';

        return match ($mode) {
            'tomorrow' => Carbon::tomorrow($tz)->setTimeFromTimeString($config['time'] ?? '09:00'),
            'datetime' => isset($config['datetime']) ? Carbon::parse($config['datetime'], $tz) : null,
            'custom_field' => $this->fromCustomField($context, $config, $tz),
            'working_hours' => $this->nextWorkingHours($context, $tz),
            default => null,
        };
    }

    protected function fromCustomField(AutomationContext $context, array $config, string $tz): ?Carbon
    {
        $key = $config['field_key'] ?? null;
        $value = $key ? $context->contact?->customFieldValue($key) : null;

        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value, $tz);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function nextWorkingHours(AutomationContext $context, string $tz): ?Carbon
    {
        $settings = $context->workspace->setting('working_hours');
        $now = Carbon::now($tz);

        if (! is_array($settings)) {
            return $now->copy()->addHour();
        }

        for ($i = 0; $i < 8; $i++) {
            $day = $now->copy()->addDays($i);
            $key = strtolower($day->format('D'));
            $daySettings = $settings[$key] ?? null;

            if (! $daySettings || empty($daySettings['enabled'])) {
                continue;
            }

            $start = $day->copy()->setTimeFromTimeString($daySettings['from'] ?? '09:00');
            $end = $day->copy()->setTimeFromTimeString($daySettings['to'] ?? '17:00');

            if ($i === 0 && $now->between($start, $end)) {
                return $now->copy()->addMinute();
            }

            if ($start->isFuture()) {
                return $start;
            }
        }

        return $now->copy()->addDay();
    }
}
