<?php

namespace App\Services\Automation;

use Carbon\Carbon;

class ConditionEvaluator
{
    public function evaluate(AutomationContext $ctx, array $config): bool
    {
        $conditions = $config['conditions'] ?? [];
        $match = $config['match'] ?? 'all';

        if ($conditions === []) {
            return true;
        }

        $results = array_map(fn (array $c) => $this->evaluateOne($ctx, $c), $conditions);

        return $match === 'any'
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    protected function evaluateOne(AutomationContext $ctx, array $condition): bool
    {
        $source = $condition['source'] ?? 'message';
        $key = $condition['key'] ?? null;
        $operator = $condition['operator'] ?? 'equals';
        $expected = $condition['value'] ?? null;

        $actual = match ($source) {
            'contact' => $ctx->resolve('contact.'.$key),
            'custom' => $ctx->resolve('custom.'.$key),
            'variable', 'variables' => $ctx->resolve('variables.'.$key),
            'message' => $ctx->resolve('message.text'),
            'tag' => $this->contactTags($ctx),
            'agent' => $ctx->conversation?->assigned_user_id !== null ? (string) $ctx->conversation->assigned_user_id : null,
            'team' => $ctx->conversation?->assigned_team_id !== null ? (string) $ctx->conversation->assigned_team_id : null,
            'conversation_status' => $ctx->conversation?->status?->value,
            'working_hours' => $this->isWithinWorkingHours($ctx) ? '1' : '0',
            'date' => Carbon::now($ctx->workspace->timezone)->toDateString(),
            'time' => Carbon::now($ctx->workspace->timezone)->format('H:i'),
            default => null,
        };

        if ($source === 'working_hours') {
            $expected = in_array($expected, ['1', 'true', 'yes', true, 1], true) ? '1' : '0';
        }

        return $this->compare($actual, $operator, $expected, $source === 'tag');
    }

    protected function contactTags(AutomationContext $ctx): string
    {
        if (! $ctx->contact) {
            return '';
        }

        return $ctx->contact->tags()->pluck('name')->implode('|');
    }

    protected function isWithinWorkingHours(AutomationContext $ctx): bool
    {
        $settings = $ctx->workspace->setting('working_hours');
        if (! is_array($settings)) {
            return true;
        }

        $now = Carbon::now($ctx->workspace->timezone);
        $day = strtolower($now->format('D'));
        $today = $settings[$day] ?? null;

        if ($today && ! empty($today['enabled'])) {
            $from = $today['from'] ?? '09:00';
            $to = $today['to'] ?? '17:00';
            $time = $now->format('H:i');

            if ($from <= $to && $time >= $from && $time <= $to) {
                return true;
            }
            if ($from > $to && $time >= $from) {
                return true;
            }
        }

        // For an overnight shift (e.g. Mon 22:00 -> 06:00), the after-midnight
        // portion belongs to the previous day's enabled schedule.
        $previous = strtolower($now->copy()->subDay()->format('D'));
        $previousConfig = $settings[$previous] ?? null;
        if ($previousConfig && ! empty($previousConfig['enabled'])) {
            $from = $previousConfig['from'] ?? '09:00';
            $to = $previousConfig['to'] ?? '17:00';

            if ($from > $to && $now->format('H:i') <= $to) {
                return true;
            }
        }

        return false;
    }

    protected function compare(?string $actual, string $operator, mixed $expected, bool $isTagList = false): bool
    {
        $expectedStr = $expected === null ? '' : (string) $expected;
        $actualStr = $actual ?? '';

        if ($isTagList) {
            $tags = array_filter(explode('|', $actualStr));
            $inList = in_array(mb_strtolower($expectedStr), array_map('mb_strtolower', $tags), true);

            return match ($operator) {
                'contains', 'equals', 'exists' => $inList,
                'not_contains', 'not_equals', 'not_exists' => ! $inList,
                'empty' => $tags === [],
                'not_empty' => $tags !== [],
                default => false,
            };
        }

        $a = mb_strtolower(trim($actualStr));
        $e = mb_strtolower(trim($expectedStr));

        return match ($operator) {
            'equals' => $a === $e,
            'not_equals' => $a !== $e,
            'contains' => $e !== '' && str_contains($a, $e),
            'not_contains' => $e === '' || ! str_contains($a, $e),
            'starts_with' => $e !== '' && str_starts_with($a, $e),
            'ends_with' => $e !== '' && str_ends_with($a, $e),
            'greater_than' => is_numeric($actualStr) && is_numeric($expectedStr)
                ? (float) $actualStr > (float) $expectedStr
                : $actualStr > $expectedStr,
            'less_than' => is_numeric($actualStr) && is_numeric($expectedStr)
                ? (float) $actualStr < (float) $expectedStr
                : ($actualStr !== '' && $actualStr < $expectedStr),
            'greater_or_equal' => is_numeric($actualStr) && is_numeric($expectedStr)
                && (float) $actualStr >= (float) $expectedStr,
            'less_or_equal' => is_numeric($actualStr) && is_numeric($expectedStr)
                && (float) $actualStr <= (float) $expectedStr,
            'empty' => $actualStr === '',
            'not_empty' => $actualStr !== '',
            'exists' => $actual !== null,
            'not_exists' => $actual === null,
            default => false,
        };
    }
}
