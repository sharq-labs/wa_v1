<?php

namespace App\Http\Controllers\Api;

use App\Models\AgentTeam;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AgentTeamController extends ApiController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        return $this->success(
            AgentTeam::query()->forWorkspace($workspace)
                ->with('members:id,name,email,avatar')
                ->orderBy('name')
                ->get(),
        );
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('manageMembers', $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100',
                Rule::unique('agent_teams', 'name')->where('workspace_id', $workspace->id)],
            'color' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:500'],
            'assignment_strategy' => ['sometimes', 'in:round_robin,least_active'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer'],
        ]);

        $team = AgentTeam::query()->create(
            collect($data)->except('member_ids')->all() + ['workspace_id' => $workspace->id],
        );

        $this->syncMembers($workspace, $team, $data['member_ids'] ?? null);

        return $this->success($team->load('members:id,name,email,avatar'), __('Team created.'), 201);
    }

    public function update(Request $request, Workspace $workspace, AgentTeam $team): JsonResponse
    {
        Gate::authorize('manageMembers', $workspace);
        abort_unless($team->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100',
                Rule::unique('agent_teams', 'name')->where('workspace_id', $workspace->id)->ignore($team->id)],
            'color' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:500'],
            'assignment_strategy' => ['sometimes', 'in:round_robin,least_active'],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer'],
        ]);

        $team->update(collect($data)->except('member_ids')->all());
        $this->syncMembers($workspace, $team, $data['member_ids'] ?? null);

        return $this->success($team->load('members:id,name,email,avatar'), __('Team updated.'));
    }

    public function destroy(Request $request, Workspace $workspace, AgentTeam $team): JsonResponse
    {
        Gate::authorize('manageMembers', $workspace);
        abort_unless($team->workspace_id === $workspace->id, 404);

        $team->delete();

        return $this->success(null, __('Team deleted.'));
    }

    protected function syncMembers(Workspace $workspace, AgentTeam $team, ?array $memberIds): void
    {
        if ($memberIds === null) {
            return;
        }

        $validIds = $workspace->users()->whereIn('users.id', $memberIds)->pluck('users.id');
        $team->members()->sync($validIds);
    }
}
