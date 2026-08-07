<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditLog;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuditLogController extends ApiController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('update', $workspace);

        $logs = AuditLog::query()
            ->where('workspace_id', $workspace->id)
            ->with('user:id,name,email')
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->latest()
            ->paginate(min((int) $request->query('per_page', 50), 200));

        return $this->success([
            'items' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
