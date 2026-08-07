<?php

namespace App\Http\Controllers\Api;

use App\Enums\AutomationRunStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Models\AutomationRun;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AnalyticsController extends ApiController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        [$from, $to] = $this->range($request, $workspace);

        $messages = Message::query()->forWorkspace($workspace)->whereBetween('created_at', [$from, $to]);
        $outbound = (clone $messages)->where('direction', MessageDirection::Outbound);
        $runs = AutomationRun::query()->forWorkspace($workspace)->whereBetween('created_at', [$from, $to]);

        $started = (clone $runs)->count();
        $completed = (clone $runs)->where('status', AutomationRunStatus::Completed)->count();

        // Average first response time (seconds) for conversations opened in range.
        $avgFirstResponse = Conversation::query()->forWorkspace($workspace)
            ->whereBetween('opened_at', [$from, $to])
            ->whereNotNull('first_agent_reply_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, opened_at, first_agent_reply_at)) as avg_seconds')
            ->value('avg_seconds');

        $avgResolution = Conversation::query()->forWorkspace($workspace)
            ->whereBetween('opened_at', [$from, $to])
            ->whereNotNull('closed_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, opened_at, closed_at)) as avg_seconds')
            ->value('avg_seconds');

        return $this->success([
            'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'series' => $this->dailySeries($workspace, $from, $to),
            'messages' => [
                'sent' => (clone $outbound)->count(),
                'delivered' => (clone $outbound)->whereIn('status', [MessageStatus::Delivered, MessageStatus::Read])->count(),
                'read' => (clone $outbound)->where('status', MessageStatus::Read)->count(),
                'failed' => (clone $outbound)->where('status', MessageStatus::Failed)->count(),
                'incoming' => (clone $messages)->where('direction', MessageDirection::Inbound)->count(),
                'agent_messages' => (clone $outbound)->where('sender_type', MessageSenderType::Agent)->count(),
            ],
            'contacts' => Contact::query()->forWorkspace($workspace)->whereBetween('created_at', [$from, $to])->count(),
            'conversations' => Conversation::query()->forWorkspace($workspace)->whereBetween('created_at', [$from, $to])->count(),
            'automation' => [
                'starts' => $started,
                'completions' => $completed,
                'completion_rate' => $started > 0 ? round($completed / $started * 100, 1) : null,
                'failures' => (clone $runs)->where('status', AutomationRunStatus::Failed)->count(),
            ],
            'agents' => [
                'avg_first_response_seconds' => $avgFirstResponse !== null ? (int) $avgFirstResponse : null,
                'avg_resolution_seconds' => $avgResolution !== null ? (int) $avgResolution : null,
            ],
        ]);
    }

    /**
     * Inbound/outbound counts per day, zero-filled so the chart keeps an even
     * x-axis on quiet days.
     *
     * @return list<array{date: string, inbound: int, outbound: int}>
     */
    protected function dailySeries(Workspace $workspace, Carbon $from, Carbon $to): array
    {
        $tz = $workspace->timezone ?: 'UTC';

        $rows = Message::query()
            ->forWorkspace($workspace)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as day, direction, COUNT(*) as total')
            ->groupBy('day', 'direction')
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $day = (string) $row->day;
            $buckets[$day] ??= ['inbound' => 0, 'outbound' => 0];
            $key = $row->direction === MessageDirection::Inbound->value ? 'inbound' : 'outbound';
            $buckets[$day][$key] += (int) $row->total;
        }

        $series = [];
        $cursor = $from->copy()->setTimezone($tz)->startOfDay();
        $last = $to->copy()->setTimezone($tz)->startOfDay();

        // Guards against an unbounded loop if a custom range is ever inverted.
        while ($cursor->lessThanOrEqualTo($last) && count($series) < 366) {
            $day = $cursor->toDateString();
            $series[] = [
                'date' => $day,
                'inbound' => $buckets[$day]['inbound'] ?? 0,
                'outbound' => $buckets[$day]['outbound'] ?? 0,
            ];
            $cursor->addDay();
        }

        return $series;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    protected function range(Request $request, Workspace $workspace): array
    {
        $tz = $workspace->timezone ?: 'UTC';
        $period = $request->query('period', '7d');

        $to = now($tz)->endOfDay();

        $from = match ($period) {
            'today' => now($tz)->startOfDay(),
            'yesterday' => now($tz)->subDay()->startOfDay(),
            '30d' => now($tz)->subDays(29)->startOfDay(),
            'custom' => $request->query('from')
                ? Carbon::parse($request->query('from'), $tz)->startOfDay()
                : now($tz)->subDays(6)->startOfDay(),
            default => now($tz)->subDays(6)->startOfDay(),
        };

        if ($period === 'yesterday') {
            $to = now($tz)->subDay()->endOfDay();
        } elseif ($period === 'custom' && $request->query('to')) {
            $to = Carbon::parse($request->query('to'), $tz)->endOfDay();
        }

        return [$from->utc(), $to->utc()];
    }
}
