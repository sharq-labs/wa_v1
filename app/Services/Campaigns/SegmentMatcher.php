<?php

namespace App\Services\Campaigns;

use App\Models\Contact;
use App\Models\Segment;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds a contact query from a segment's stored filter definition.
 *
 * Filter shape:
 * {
 *   "match": "all" | "any",
 *   "conditions": [
 *     {"source": "contact|custom|tag", "key": "...", "operator": "...", "value": "..."}
 *   ]
 * }
 */
class SegmentMatcher
{
    public function query(Workspace $workspace, Segment $segment): Builder
    {
        return $this->buildQuery($workspace, $segment->filters ?? []);
    }

    public function buildQuery(Workspace $workspace, array $filters): Builder
    {
        $query = Contact::query()->forWorkspace($workspace)->where('status', 'active');

        $conditions = $filters['conditions'] ?? [];
        $boolean = ($filters['match'] ?? 'all') === 'any' ? 'or' : 'and';

        if ($conditions === []) {
            return $query;
        }

        $query->where(function (Builder $group) use ($conditions, $boolean) {
            foreach ($conditions as $condition) {
                $this->applyCondition($group, $condition, $boolean);
            }
        });

        return $query;
    }

    protected function applyCondition(Builder $query, array $condition, string $boolean): void
    {
        $source = $condition['source'] ?? 'contact';
        $key = (string) ($condition['key'] ?? '');
        $operator = $condition['operator'] ?? 'equals';
        $value = (string) ($condition['value'] ?? '');

        $method = $boolean === 'or' ? 'orWhere' : 'where';

        match ($source) {
            'tag' => $this->applyTagCondition($query, $operator, $value, $boolean),
            'custom' => $query->{$method}(function (Builder $q) use ($key, $operator, $value) {
                $q->whereHas('customFieldValues', function (Builder $sub) use ($key, $operator, $value) {
                    $sub->whereHas('customField', fn (Builder $f) => $f->where('key', $key));
                    $this->applyValueOperator($sub, 'value', $operator, $value);
                });
            }),
            default => $query->{$method}(function (Builder $q) use ($key, $operator, $value) {
                $column = in_array($key, ['first_name', 'last_name', 'display_name', 'email', 'country', 'language', 'phone_number', 'opt_in_status'], true)
                    ? $key
                    : 'phone_number';
                $this->applyValueOperator($q, $column, $operator, $value);
            }),
        };
    }

    protected function applyTagCondition(Builder $query, string $operator, string $value, string $boolean): void
    {
        $method = $boolean === 'or' ? 'orWhere' : 'where';

        $has = in_array($operator, ['equals', 'contains', 'exists'], true);

        $query->{$method}(function (Builder $q) use ($has, $value) {
            $has
                ? $q->whereHas('tags', fn (Builder $t) => $t->where('name', $value))
                : $q->whereDoesntHave('tags', fn (Builder $t) => $t->where('name', $value));
        });
    }

    protected function applyValueOperator(Builder $query, string $column, string $operator, string $value): void
    {
        match ($operator) {
            'equals' => $query->where($column, $value),
            'not_equals' => $query->where($column, '!=', $value),
            'contains' => $query->where($column, 'like', "%{$value}%"),
            'not_contains' => $query->where($column, 'not like', "%{$value}%"),
            'starts_with' => $query->where($column, 'like', "{$value}%"),
            'ends_with' => $query->where($column, 'like', "%{$value}"),
            'greater_than' => $query->whereRaw("CAST({$column} AS DECIMAL(20,4)) > ?", [(float) $value]),
            'less_than' => $query->whereRaw("CAST({$column} AS DECIMAL(20,4)) < ?", [(float) $value]),
            'greater_or_equal' => $query->whereRaw("CAST({$column} AS DECIMAL(20,4)) >= ?", [(float) $value]),
            'less_or_equal' => $query->whereRaw("CAST({$column} AS DECIMAL(20,4)) <= ?", [(float) $value]),
            'empty' => $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, '')),
            'not_empty' => $query->whereNotNull($column)->where($column, '!=', ''),
            default => $query->where($column, $value),
        };
    }
}
