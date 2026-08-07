<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the {workspace} route parameter, verifies the authenticated user
 * is a member, and binds the workspace into the container. workspace_id is
 * never trusted from request input.
 */
class ResolveWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->route('workspace');

        if (! $workspace instanceof Workspace) {
            $workspace = Workspace::query()->find($workspace);
        }

        $user = $request->user();

        if (! $workspace || ! $user) {
            return response()->json([
                'success' => false,
                'message' => __('Workspace not found.'),
                'errors' => [],
            ], 404);
        }

        if (! $user->is_super_admin && ! $user->belongsToWorkspace($workspace)) {
            return response()->json([
                'success' => false,
                'message' => __('You do not have access to this workspace.'),
                'errors' => [],
            ], 403);
        }

        app()->instance('currentWorkspace', $workspace);
        $request->attributes->set('workspace', $workspace);

        return $next($request);
    }
}
