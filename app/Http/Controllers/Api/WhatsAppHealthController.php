<?php

namespace App\Http\Controllers\Api;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WhatsAppHealthController extends ApiController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $accounts = WhatsAppAccount::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (WhatsAppAccount $account) => $this->accountHealth($workspace, $account));

        $connected = $accounts->where('connected', true)->count();
        $critical = $accounts->filter(fn (array $account) => $account['health'] === 'critical')->count();
        $warning = $accounts->filter(fn (array $account) => $account['health'] === 'warning')->count();

        return $this->success([
            'status' => $critical > 0 ? 'critical' : ($warning > 0 ? 'warning' : ($connected > 0 ? 'healthy' : 'disconnected')),
            'accounts_count' => $accounts->count(),
            'connected_count' => $connected,
            'critical_count' => $critical,
            'warning_count' => $warning,
            'accounts' => $accounts->values(),
        ]);
    }

    protected function accountHealth(Workspace $workspace, WhatsAppAccount $account): array
    {
        $templateCounts = WhatsAppTemplate::query()
            ->where('workspace_id', $workspace->id)
            ->where('whatsapp_account_id', $account->id)
            ->selectRaw('LOWER(status) AS status_key, COUNT(*) AS aggregate')
            ->groupBy('status_key')
            ->pluck('aggregate', 'status_key')
            ->map(fn ($value) => (int) $value)
            ->all();

        $lastWebhook = WebhookEvent::query()
            ->where('workspace_id', $workspace->id)
            ->where('provider', 'meta')
            ->latest('id')
            ->first(['id', 'event_type', 'status', 'attempts', 'error_message', 'processed_at', 'created_at']);

        $failedMessages = Message::query()
            ->where('workspace_id', $workspace->id)
            ->where('whatsapp_account_id', $account->id)
            ->where('direction', MessageDirection::Outbound)
            ->where('status', MessageStatus::Failed)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $latestFailure = Message::query()
            ->where('workspace_id', $workspace->id)
            ->where('whatsapp_account_id', $account->id)
            ->where('direction', MessageDirection::Outbound)
            ->where('status', MessageStatus::Failed)
            ->latest('id')
            ->first(['id', 'error_code', 'error_message', 'failed_at', 'created_at']);

        $connected = $account->isConnected();
        $quality = strtoupper((string) ($account->quality_rating ?? 'UNKNOWN'));
        $rejectedOrDisabled = ($templateCounts['rejected'] ?? 0)
            + ($templateCounts['paused'] ?? 0)
            + ($templateCounts['disabled'] ?? 0);

        $health = 'healthy';
        $issues = [];

        if (! $connected) {
            $health = 'critical';
            $issues[] = 'WhatsApp account is disconnected.';
        }
        if (in_array($quality, ['RED', 'LOW'], true)) {
            $health = 'critical';
            $issues[] = 'WhatsApp quality rating is low.';
        } elseif (in_array($quality, ['YELLOW', 'MEDIUM'], true) && $health !== 'critical') {
            $health = 'warning';
            $issues[] = 'WhatsApp quality rating needs attention.';
        }
        if ($failedMessages > 0 && $health !== 'critical') {
            $health = 'warning';
            $issues[] = "{$failedMessages} outbound message(s) failed in the last 24 hours.";
        }
        if ($rejectedOrDisabled > 0 && $health !== 'critical') {
            $health = 'warning';
            $issues[] = "{$rejectedOrDisabled} template(s) are rejected, paused or disabled.";
        }
        if ($account->last_sync_at?->lt(now()->subDay()) && $health === 'healthy') {
            $health = 'warning';
            $issues[] = 'Account/template sync is older than 24 hours.';
        }

        return [
            'id' => $account->id,
            'provider' => $account->provider,
            'display_phone_number' => $account->display_phone_number,
            'verified_name' => $account->verified_name,
            'connected' => $connected,
            'status' => $account->status,
            'health' => $health,
            'quality_rating' => $account->quality_rating,
            'messaging_limit' => $account->messaging_limit,
            'last_sync_at' => $account->last_sync_at?->toIso8601String(),
            'templates' => [
                'total' => array_sum($templateCounts),
                'approved' => $templateCounts['approved'] ?? 0,
                'pending' => ($templateCounts['pending'] ?? 0) + ($templateCounts['pending_review'] ?? 0),
                'rejected' => $templateCounts['rejected'] ?? 0,
                'paused' => $templateCounts['paused'] ?? 0,
                'disabled' => $templateCounts['disabled'] ?? 0,
            ],
            'outbound_failures_24h' => $failedMessages,
            'latest_failure' => $latestFailure,
            'last_webhook' => $lastWebhook,
            'issues' => $issues,
        ];
    }
}
