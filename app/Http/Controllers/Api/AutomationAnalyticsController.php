<?php

namespace App\Http\Controllers\Api;

use App\Models\Automation;
use App\Models\AutomationGoalEvent;
use App\Models\AutomationRun;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AutomationAnalyticsController extends ApiController
{
    public function show(Request $request, Workspace $workspace, Automation $automation): JsonResponse
    {
        Gate::authorize('view', $workspace);
        abort_unless($automation->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'days' => ['sometimes', 'integer', 'between:1,365'],
        ]);
        $days = (int) ($data['days'] ?? 30);
        $since = now()->subDays($days);

        $runQuery = AutomationRun::query()
            ->where('workspace_id', $workspace->id)
            ->where('automation_id', $automation->id)
            ->where('created_at', '>=', $since);

        $totalRuns = (clone $runQuery)->count();
        $statusCounts = (clone $runQuery)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($value) => (int) $value)
            ->all();

        $nodeRows = DB::table('automation_run_steps as steps')
            ->join('automation_runs as runs', 'runs.id', '=', 'steps.automation_run_id')
            ->where('runs.workspace_id', $workspace->id)
            ->where('runs.automation_id', $automation->id)
            ->where('steps.created_at', '>=', $since)
            ->selectRaw(
                "steps.node_id, steps.node_type, COUNT(DISTINCT steps.automation_run_id) AS entered, "
                ."SUM(CASE WHEN steps.status = 'failed' THEN 1 ELSE 0 END) AS failed, "
                ."SUM(CASE WHEN steps.status = 'waiting' THEN 1 ELSE 0 END) AS waiting, "
                ."COUNT(*) AS executions"
            )
            ->groupBy('steps.node_id', 'steps.node_type')
            ->get();

        $definitionNodes = collect(
            $automation->publishedVersion?->definitionNodes()
            ?? $automation->draft_definition['nodes']
            ?? [],
        )->keyBy('id');

        $nodes = $nodeRows->map(function ($row) use ($definitionNodes, $totalRuns) {
            $entered = (int) $row->entered;
            $definition = $definitionNodes->get($row->node_id, []);

            return [
                'node_id' => $row->node_id,
                'node_type' => $row->node_type,
                'entered' => $entered,
                'executions' => (int) $row->executions,
                'failed' => (int) $row->failed,
                'waiting' => (int) $row->waiting,
                'reach_rate' => $totalRuns > 0 ? round(($entered / $totalRuns) * 100, 2) : 0,
                'config' => $row->node_type === 'goal'
                    ? ['goal_name' => data_get($definition, 'config.goal_name', 'Conversion')]
                    : null,
            ];
        })->values();

        $goalQuery = AutomationGoalEvent::query()
            ->where('workspace_id', $workspace->id)
            ->where('automation_id', $automation->id)
            ->where('created_at', '>=', $since);

        $convertedRuns = (clone $goalQuery)->distinct('automation_run_id')->count('automation_run_id');
        $goals = (clone $goalQuery)
            ->selectRaw('goal_name, currency, COUNT(DISTINCT automation_run_id) AS conversions, SUM(value) AS total_value, AVG(value) AS average_value')
            ->groupBy('goal_name', 'currency')
            ->orderByDesc('conversions')
            ->get()
            ->map(fn ($row) => [
                'goal_name' => $row->goal_name,
                'currency' => $row->currency,
                'conversions' => (int) $row->conversions,
                'total_value' => $row->total_value !== null ? (float) $row->total_value : null,
                'average_value' => $row->average_value !== null ? round((float) $row->average_value, 4) : null,
            ]);

        return $this->success([
            'period_days' => $days,
            'runs' => [
                'total' => $totalRuns,
                'completed' => (int) ($statusCounts['completed'] ?? 0),
                'failed' => (int) ($statusCounts['failed'] ?? 0),
                'waiting' => (int) ($statusCounts['waiting'] ?? 0),
                'running' => (int) ($statusCounts['running'] ?? 0),
            ],
            'conversion' => [
                'converted_runs' => $convertedRuns,
                'rate' => $totalRuns > 0 ? round(($convertedRuns / $totalRuns) * 100, 2) : 0,
            ],
            'nodes' => $nodes,
            'goals' => $goals,
        ]);
    }
}
