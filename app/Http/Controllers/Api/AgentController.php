<?php

namespace App\Http\Controllers\Api;

use App\Enums\AgentAvailability;
use App\Enums\AgentStatus;
use App\Events\AgentStatusChanged;
use App\Models\AgentProfile;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AgentController extends ApiController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $agents = AgentProfile::query()
            ->forWorkspace($workspace)
            ->with('user:id,name,email,avatar')
            ->get();

        return $this->success($agents);
    }

    /** Update the authenticated agent's own status/availability. */
    public function updateSelf(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('useInbox', $workspace);

        $data = $request->validate([
            'status' => ['sometimes', Rule::enum(AgentStatus::class)],
            'availability' => ['sometimes', Rule::enum(AgentAvailability::class)],
        ]);

        $profile = AgentProfile::query()
            ->forWorkspace($workspace)
            ->firstOrCreate(['user_id' => $request->user()->id]);

        $profile->fill($data);
        $profile->last_active_at = now();
        $profile->save();

        broadcast(new AgentStatusChanged($workspace->id, [
            'user_id' => $request->user()->id,
            'status' => $profile->status->value,
            'availability' => $profile->availability->value,
        ]));

        return $this->success($profile);
    }

    /** Admin update of any agent profile (e.g. max conversations). */
    public function update(Request $request, Workspace $workspace, AgentProfile $agentProfile): JsonResponse
    {
        Gate::authorize('manageMembers', $workspace);
        abort_unless($agentProfile->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'availability' => ['sometimes', Rule::enum(AgentAvailability::class)],
            'maximum_conversations' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);

        $agentProfile->update($data);

        return $this->success($agentProfile, __('Agent updated.'));
    }
}
