<?php

namespace App\Services\Automation;

/**
 * Condition evaluation against the simulator's virtual state array
 * (mirrors ConditionEvaluator semantics without touching the database).
 */
class ConditionEvaluatorForSimulation
{
    public function evaluate(array $state, array $config): bool
    {
        $conditions = $config['conditions'] ?? [];
        $match = $config['match'] ?? 'all';

        if ($conditions === []) {
            return true;
        }

        $results = array_map(fn (array $c) => $this->one($state, $c), $conditions);

        return $match === 'any'
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    protected function one(array $state, array $condition): bool
    {
        $source = $condition['source'] ?? 'message';
        $key = $condition['key'] ?? null;
        $operator = $condition['operator'] ?? 'equals';
        $expected = (string) ($condition['value'] ?? '');

        $actual = match ($source) {
            'contact' => (string) ($state['contact'][$key] ?? ''),
            'custom' => (string) ($state['custom_fields'][$key] ?? ''),
            'variable', 'variables' => (string) ($state['variables'][$key] ?? ''),
            'message' => (string) ($state['last_message'] ?? ''),
            'tag' => implode('|', $state['tags'] ?? []),
            'conversation_status' => $state['conversation']['status'] ?? 'open',
            'working_hours' => '1',
            default => '',
        };

        if ($source === 'tag') {
            $tags = array_map('mb_strtolower', $state['tags'] ?? []);
            $has = in_array(mb_strtolower($expected), $tags, true);

            return match ($operator) {
                'contains', 'equals', 'exists' => $has,
                'not_contains', 'not_equals', 'not_exists' => ! $has,
                default => false,
            };
        }

        $a = mb_strtolower(trim($actual));
        $e = mb_strtolower(trim($expected));

        return match ($operator) {
            'equals' => $a === $e,
            'not_equals' => $a !== $e,
            'contains' => $e !== '' && str_contains($a, $e),
            'not_contains' => $e === '' || ! str_contains($a, $e),
            'starts_with' => str_starts_with($a, $e),
            'ends_with' => str_ends_with($a, $e),
            'greater_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'less_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'greater_or_equal' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'less_or_equal' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            'empty' => $a === '',
            'not_empty' => $a !== '',
            'exists' => $a !== '',
            'not_exists' => $a === '',
            default => false,
        };
    }
}
