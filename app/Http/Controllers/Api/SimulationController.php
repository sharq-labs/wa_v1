<?php

namespace App\Http\Controllers\Api;

use App\Models\Automation;
use App\Models\Workspace;
use App\Services\Automation\SimulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SimulationController extends ApiController
{
    public function start(Request $request, Workspace $workspace, Automation $automation, SimulationService $simulator): JsonResponse
    {
        Gate::authorize('manageAutomations', $workspace);
        abort_unless($automation->workspace_id === $workspace->id, 404);

        $definition = $automation->draft_definition
            ?? $automation->publishedVersion?->definition;

        if (! $definition || ($definition['nodes'] ?? []) === []) {
            return $this->error(__('Nothing to simulate — the flow is empty.'));
        }

        return $this->success($simulator->start($workspace, $automation, $definition), __('Simulation started.'));
    }

    public function message(Request $request, Workspace $workspace, Automation $automation, SimulationService $simulator): JsonResponse
    {
        Gate::authorize('manageAutomations', $workspace);
        abort_unless($automation->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'session_id' => ['required', 'string', 'uuid'],
            'text' => ['required', 'string', 'max:4096'],
            'reply_id' => ['nullable', 'string', 'max:64'],
        ]);

        $result = $simulator->sendCustomerMessage($data['session_id'], $data['text'], $data['reply_id'] ?? null);

        if (isset($result['error'])) {
            return $this->error($result['error'], [], 410);
        }

        return $this->success($result);
    }
}
