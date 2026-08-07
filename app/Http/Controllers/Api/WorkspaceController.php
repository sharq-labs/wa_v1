<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WorkspaceController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $workspaces = $request->user()->workspaces()->orderBy('name')->get();

        return $this->success(WorkspaceResource::collection($workspaces));
    }

    public function store(Request $request, WorkspaceService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'timezone'],
            'country' => ['nullable', 'string', 'size:2'],
            'currency' => ['nullable', 'string', 'size:3'],
            'locale' => ['nullable', 'string', 'in:en,ar'],
        ]);

        $workspace = $service->createForUser($request->user(), $data['name'], collect($data)->except('name')->filter()->all());

        $audit->log('workspace.create', $workspace, $request->user(), $workspace);

        return $this->success(new WorkspaceResource($workspace), __('Workspace created.'), 201);
    }

    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        return $this->success(new WorkspaceResource($workspace));
    }

    public function update(Request $request, Workspace $workspace, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('update', $workspace);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'timezone' => ['sometimes', 'string', 'timezone'],
            'country' => ['nullable', 'string', 'size:2'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'locale' => ['sometimes', 'string', 'in:en,ar'],
            'industry' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'url', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'settings' => ['sometimes', 'array'],
        ]);

        if (isset($data['settings'])) {
            $data['settings'] = array_merge($workspace->settings ?? [], $data['settings']);
        }

        $workspace->update($data);

        $audit->log('workspace.update', $workspace, $request->user(), $workspace, ['fields' => array_keys($data)]);

        return $this->success(new WorkspaceResource($workspace), __('Workspace updated.'));
    }

    public function destroy(Request $request, Workspace $workspace, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('delete', $workspace);

        $audit->log('workspace.delete', $workspace, $request->user(), $workspace);

        $workspace->delete();

        if ($request->user()->current_workspace_id === $workspace->id) {
            $next = $request->user()->workspaces()->where('workspaces.id', '!=', $workspace->id)->first();
            $request->user()->forceFill(['current_workspace_id' => $next?->id])->save();
        }

        return $this->success(null, __('Workspace deleted.'));
    }

    public function switch(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $request->user()->forceFill(['current_workspace_id' => $workspace->id])->save();

        return $this->success(new WorkspaceResource($workspace), __('Workspace switched.'));
    }

    public function uploadLogo(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('update', $workspace);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $path = $request->file('logo')->store("workspaces/{$workspace->id}", 'public');
        $workspace->update(['logo' => '/storage/'.$path]);

        return $this->success(new WorkspaceResource($workspace), __('Logo updated.'));
    }

    public function completeOnboarding(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('update', $workspace);

        $workspace->update(['onboarded_at' => now()]);

        return $this->success(new WorkspaceResource($workspace), __('Onboarding completed.'));
    }
}
