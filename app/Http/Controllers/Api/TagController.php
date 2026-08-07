<?php

namespace App\Http\Controllers\Api;

use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TagController extends ApiController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        return $this->success(
            Tag::query()->forWorkspace($workspace)
                ->withCount('contacts')
                ->orderBy('name')
                ->get(),
        );
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('manageContacts', $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100',
                Rule::unique('tags', 'name')->where('workspace_id', $workspace->id)],
            'color' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $tag = Tag::query()->create($data + ['workspace_id' => $workspace->id]);

        return $this->success($tag, __('Tag created.'), 201);
    }

    public function update(Request $request, Workspace $workspace, Tag $tag): JsonResponse
    {
        Gate::authorize('manageContacts', $workspace);
        abort_unless($tag->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100',
                Rule::unique('tags', 'name')->where('workspace_id', $workspace->id)->ignore($tag->id)],
            'color' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $tag->update($data);

        return $this->success($tag, __('Tag updated.'));
    }

    public function destroy(Request $request, Workspace $workspace, Tag $tag): JsonResponse
    {
        Gate::authorize('manageContacts', $workspace);
        abort_unless($tag->workspace_id === $workspace->id, 404);

        $tag->delete();

        return $this->success(null, __('Tag deleted.'));
    }
}
