<?php

namespace App\Http\Controllers\Api;

use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;

class NotificationController extends ApiController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $notifications = $request->user()->notifications()
            ->where('data->workspace_id', $workspace->id)
            ->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            ->latest()
            ->paginate(min((int) $request->query('per_page', 30), 100));

        return $this->success([
            'items' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
                'unread' => $request->user()->unreadNotifications()
                    ->where('data->workspace_id', $workspace->id)
                    ->count(),
            ],
        ]);
    }

    public function read(Request $request, Workspace $workspace, string $notification): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $item = $request->user()->notifications()
            ->whereKey($notification)
            ->where('data->workspace_id', $workspace->id)
            ->firstOrFail();
        $item->markAsRead();

        return $this->success($item->fresh());
    }

    public function readAll(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $count = $request->user()->unreadNotifications()
            ->where('data->workspace_id', $workspace->id)
            ->update(['read_at' => now()]);

        return $this->success(['marked_read' => $count]);
    }
}
