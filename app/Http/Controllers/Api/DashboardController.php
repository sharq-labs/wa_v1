<?php

namespace App\Http\Controllers\Api;

use App\Enums\AgentStatus;
use App\Enums\AutomationRunStatus;
use App\Enums\AutomationState;
use App\Enums\MessageDirection;
use App\Models\AgentProfile;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends ApiController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $today = now($workspace->timezone)->startOfDay()->utc();

        $messagesToday = Message::query()->forWorkspace($workspace)->where('created_at', '>=', $today);

        return $this->success([
            'metrics' => [
                'messages_today' => (clone $messagesToday)->count(),
                'incoming_today' => (clone $messagesToday)->where('direction', MessageDirection::Inbound)->count(),
                'outgoing_today' => (clone $messagesToday)->where('direction', MessageDirection::Outbound)->count(),
                'contacts' => Contact::query()->forWorkspace($workspace)->count(),
                'open_conversations' => Conversation::query()->forWorkspace($workspace)->where('status', 'open')->count(),
                'unassigned_conversations' => Conversation::query()->forWorkspace($workspace)
                    ->where('status', 'open')->whereNull('assigned_user_id')->count(),
                'active_agents' => AgentProfile::query()->forWorkspace($workspace)
                    ->where('status', '!=', AgentStatus::Offline)->count(),
                'published_bots' => Automation::query()->forWorkspace($workspace)
                    ->where('status', AutomationState::Published)->count(),
                'automation_runs_today' => AutomationRun::query()->forWorkspace($workspace)
                    ->where('created_at', '>=', $today)->count(),
                'failed_automations_today' => AutomationRun::query()->forWorkspace($workspace)
                    ->where('created_at', '>=', $today)->where('status', 'failed')->count(),
            ],
            'campaigns' => Campaign::query()->forWorkspace($workspace)
                ->latest()->limit(5)
                ->get(['id', 'name', 'status', 'total_recipients', 'sent_count', 'delivered_count', 'read_count', 'failed_count']),
            'charts' => $this->charts($workspace),
        ]);
    }

    protected function charts(Workspace $workspace): array
    {
        $from = now()->subDays(13)->startOfDay();

        $messagesByDay = Message::query()->forWorkspace($workspace)
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day, direction, count(*) as total')
            ->groupBy('day', 'direction')
            ->get();

        $contactsByDay = Contact::query()->forWorkspace($workspace)
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day, count(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $runsByDay = AutomationRun::query()->forWorkspace($workspace)
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as day, status, count(*) as total')
            ->groupBy('day', 'status')
            ->get();

        $days = collect(range(0, 13))->map(fn ($i) => $from->copy()->addDays($i)->toDateString());

        return [
            'messages_by_date' => $days->map(fn ($day) => [
                'date' => $day,
                'inbound' => (int) $messagesByDay->first(fn ($r) => $r->day === $day && $r->direction === MessageDirection::Inbound)?->total,
                'outbound' => (int) $messagesByDay->first(fn ($r) => $r->day === $day && $r->direction === MessageDirection::Outbound)?->total,
            ])->values(),
            'contacts_by_date' => $days->map(fn ($day) => [
                'date' => $day,
                'total' => (int) ($contactsByDay[$day] ?? 0),
            ])->values(),
            'automation_by_date' => $days->map(fn ($day) => [
                'date' => $day,
                'started' => (int) $runsByDay->filter(fn ($r) => $r->day === $day)->sum('total'),
                'completed' => (int) $runsByDay->first(fn ($r) => $r->day === $day && $r->status === AutomationRunStatus::Completed)?->total,
            ])->values(),
        ];
    }
}
