<?php

namespace App\Http\Controllers\Api;

use App\Enums\AutomationState;
use App\Models\Automation;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\Automation\FlowValidator;
use App\Services\Billing\EntitlementsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AutomationController extends ApiController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $automations = Automation::query()
            ->forWorkspace($workspace)
            ->withCount(['runs', 'runs as active_runs_count' => fn ($q) => $q->whereIn('status', ['running', 'waiting'])])
            ->orderByDesc('priority')
            ->orderBy('name')
            ->get();

        return $this->success($automations);
    }

    public function store(Request $request, Workspace $workspace, EntitlementsService $entitlements): JsonResponse
    {
        Gate::authorize('manageAutomations', $workspace);

        if (! $entitlements->canAddAutomation($workspace)) {
            return $this->error(__('Your plan automation limit has been reached. Please upgrade.'), [], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['sometimes', 'integer', 'between:-100,100'],
        ]);

        $automation = Automation::query()->create($data + [
            'workspace_id' => $workspace->id,
            'status' => AutomationState::Draft,
            'created_by' => $request->user()->id,
            'draft_definition' => ['nodes' => [], 'edges' => []],
        ]);

        return $this->success($automation, __('Automation created.'), 201);
    }

    public function show(Request $request, Workspace $workspace, Automation $automation): JsonResponse
    {
        Gate::authorize('manageAutomations', $workspace);
        abort_unless($automation->workspace_id === $workspace->id, 404);

        $automation->load('publishedVersion:id,automation_id,version,published_at');

        return $this->success([
            'automation' => $automation,
            'definition' => $automation->draft_definition
                ?? $automation->publishedVersion?->definition
                ?? ['nodes' => [], 'edges' => []],
        ]);
    }

    public function update(Request $request, Workspace $workspace, Automation $automation): JsonResponse
    {
        Gate::authorize('manageAutomations', $workspace);
        abort_unless($automation->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'priority' => ['sometimes', 'integer', 'between:-100,100'],
        ]);

        $automation->update($data);

        return $this->success($automation, __('Automation updated.'));
    }

    public function saveDraft(Request $request, Workspace $workspace, Automation $automation): JsonResponse
    {
        Gate::authorize('manageAutomations', $workspace);
        abort_unless($automation->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'definition' => ['required', 'array'],
            'definition.nodes' => ['present', 'array'],
            'definition.edges' => ['present', 'array'],
        ]);

        $automation->update(['draft_definition' => $data['definition']]);

        return $this->success(['saved_at' => now()->toIso8601String()], __('Draft saved.'));
    }

    public function validateDraft(Request $request, Workspace $workspace, Automation $automation, FlowValidator $validator): JsonResponse
    {
        Gate::authorize('manageAutomations', $workspace);
        abort_unless($automation->workspace_id === $workspace->id, 404);

        $definition = $automation->draft_definition ?? ['nodes' => [], 'edges' => []];
        $errors = $validator->validate($workspace, $definition);

        return $this->success(['valid' => $errors === [], 'errors' => $errors]);
    }

    public function publish(Request $request, Workspace $workspace, Automation $automation, FlowValidator $validator, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('manageAutomations', $workspace);
        abort_unless($automation->workspace_id === $workspace->id, 404);

        $definition = $automation->draft_definition ?? $automation->publishedVersion?->definition;

        if (! $definition) {
            return $this->error(__('Nothing to publish yet.'));
        }

        $errors = $validator->validate($workspace, $definition);
        if ($errors !== []) {
            return $this->error(__('The flow has validation errors and cannot be published.'), [
                'validation' => $errors,
            ]);
        }

        $version = DB::transaction(function () use ($automation, $definition, $request) {
            $number = ($automation->versions()->max('version') ?? 0) + 1;

            $version = $automation->versions()->create([
                'version' => $number,
                'definition' => $definition,
                'published_by' => $request->user()->id,
                'published_at' => now(),
            ]);

            foreach ($definition['nodes'] ?? [] as $node) {
                $version->nodes()->create([
                    'node_id' => $node['id'],
                    'type' => $node['type'],
                    'config' => $node['config'] ?? null,
                    'position' => $node['position'] ?? null,
                ]);
            }

            foreach ($definition['edges'] ?? [] as $edge) {
                $version->edges()->create([
                    'edge_id' => $edge['id'] ?? ($edge['source'].'-'.$edge['target']),
                    'source_node_id' => $edge['source'],
                    'source_handle' => $edge['sourceHandle'] ?? null,
                    'target_node_id' => $edge['target'],
                ]);
            }

            $automation->update([
                'status' => AutomationState::Published,
                'published_version_id' => $version->id,
            ]);

            return $version;
        });

        $audit->log('automation.publish', $workspace, $request->user(), $automation, ['version' => $version->version]);

        return $this->success([
            'automation' => $automation->fresh(),
            'version' => $version->version,
        ], __('Automation published.'));
    }

    public function setStatus(Request $request, Workspace $workspace, Automation $automation): JsonResponse
    {
        Gate::authorize('manageAutomations', $workspace);
        abort_unless($automation->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'status' => ['required', 'in:published,paused,archived'],
        ]);

        if ($data['status'] === 'published' && ! $automation->published_version_id) {
            return $this->error(__('Publish the automation before activating it.'));
        }

        $automation->update(['status' => $data['status']]);

        return $this->success($automation, __('Automation status updated.'));
    }

    public function destroy(Request $request, Workspace $workspace, Automation $automation, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('manageAutomations', $workspace);
        abort_unless($automation->workspace_id === $workspace->id, 404);

        $audit->log('automation.delete', $workspace, $request->user(), $automation, ['name' => $automation->name]);
        $automation->delete();

        return $this->success(null, __('Automation deleted.'));
    }

    public function runs(Request $request, Workspace $workspace, Automation $automation): JsonResponse
    {
        Gate::authorize('manageAutomations', $workspace);
        abort_unless($automation->workspace_id === $workspace->id, 404);

        $runs = $automation->runs()
            ->with(['contact:id,first_name,last_name,display_name,phone_number', 'version:id,version'])
            ->latest()
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return $this->success([
            'items' => $runs->items(),
            'meta' => [
                'current_page' => $runs->currentPage(),
                'last_page' => $runs->lastPage(),
                'total' => $runs->total(),
            ],
        ]);
    }

    public function runDetail(Request $request, Workspace $workspace, Automation $automation, string $runUuid): JsonResponse
    {
        Gate::authorize('manageAutomations', $workspace);
        abort_unless($automation->workspace_id === $workspace->id, 404);

        $run = $automation->runs()
            ->where('uuid', $runUuid)
            ->with([
                'steps' => fn ($q) => $q->orderBy('id'),
                'contact:id,first_name,last_name,display_name,phone_number',
                'variables',
                'version:id,version',
            ])
            ->firstOrFail();

        return $this->success($run);
    }
}
