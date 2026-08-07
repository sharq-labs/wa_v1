<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ContactResource;
use App\Models\Segment;
use App\Models\Workspace;
use App\Services\Campaigns\SegmentMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SegmentController extends ApiController
{
    public function index(Request $request, Workspace $workspace, SegmentMatcher $matcher): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $segments = Segment::query()->forWorkspace($workspace)->orderBy('name')->get()
            ->map(fn (Segment $segment) => [
                ...$segment->toArray(),
                'contact_count' => $matcher->query($workspace, $segment)->count(),
            ]);

        return $this->success($segments);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('manageCampaigns', $workspace);

        $data = $this->validateSegment($request, $workspace);

        $segment = Segment::query()->create($data + ['workspace_id' => $workspace->id]);

        return $this->success($segment, __('Segment created.'), 201);
    }

    public function update(Request $request, Workspace $workspace, Segment $segment): JsonResponse
    {
        Gate::authorize('manageCampaigns', $workspace);
        abort_unless($segment->workspace_id === $workspace->id, 404);

        $data = $this->validateSegment($request, $workspace, $segment);

        $segment->update($data);

        return $this->success($segment, __('Segment updated.'));
    }

    public function destroy(Request $request, Workspace $workspace, Segment $segment): JsonResponse
    {
        Gate::authorize('manageCampaigns', $workspace);
        abort_unless($segment->workspace_id === $workspace->id, 404);

        $segment->delete();

        return $this->success(null, __('Segment deleted.'));
    }

    public function preview(Request $request, Workspace $workspace, SegmentMatcher $matcher): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $data = $request->validate([
            'filters' => ['required', 'array'],
        ]);

        $query = $matcher->buildQuery($workspace, $data['filters']);

        return $this->success([
            'count' => (clone $query)->count(),
            'sample' => ContactResource::collection($query->with('tags')->limit(10)->get()),
        ]);
    }

    protected function validateSegment(Request $request, Workspace $workspace, ?Segment $segment = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255',
                Rule::unique('segments', 'name')->where('workspace_id', $workspace->id)->ignore($segment?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'filters' => ['required', 'array'],
            'filters.match' => ['required', 'in:all,any'],
            'filters.conditions' => ['present', 'array'],
            'filters.conditions.*.source' => ['required', 'in:contact,custom,tag'],
            'filters.conditions.*.key' => ['nullable', 'string', 'max:100'],
            'filters.conditions.*.operator' => ['required', 'string', 'max:30'],
            'filters.conditions.*.value' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
