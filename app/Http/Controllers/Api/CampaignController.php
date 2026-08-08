<?php

namespace App\Http\Controllers\Api;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\Workspace;
use App\Services\AuditLogger;
use App\Services\Billing\EntitlementsService;
use App\Services\Campaigns\CampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CampaignController extends ApiController
{
    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        Gate::authorize('view', $workspace);

        $campaigns = Campaign::query()->forWorkspace($workspace)
            ->with(['template:id,name,language,category', 'whatsappAccount:id,display_phone_number'])
            ->latest()->paginate(min((int) $request->query('per_page', 25), 100));

        return $this->success([
            'items' => $campaigns->items(),
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'last_page' => $campaigns->lastPage(),
                'total' => $campaigns->total(),
            ],
        ]);
    }

    public function preview(Request $request, Workspace $workspace, CampaignService $service): JsonResponse
    {
        Gate::authorize('manageCampaigns', $workspace);

        $data = $request->validate([
            'whatsapp_account_id' => ['required', 'integer', Rule::exists('whatsapp_accounts', 'id')->where('workspace_id', $workspace->id)],
            'whatsapp_template_id' => ['required', 'integer', Rule::exists('whatsapp_templates', 'id')->where('workspace_id', $workspace->id)],
            'audience_type' => ['required', 'in:all,tag,segment,contacts'],
            'audience_config' => ['nullable', 'array'],
        ]);

        $account = $workspace->whatsappAccounts()->findOrFail($data['whatsapp_account_id']);
        $template = $workspace->templates()
            ->where('whatsapp_account_id', $account->id)
            ->find($data['whatsapp_template_id']);

        if (! $template || ! $template->isApproved()) {
            return $this->error(__('Select an approved template that belongs to this WhatsApp account.'), [], 422);
        }

        return $this->success($service->previewAudience(
            $workspace,
            $account->id,
            $data['audience_type'],
            $data['audience_config'] ?? [],
        ));
    }

    public function store(Request $request, Workspace $workspace, EntitlementsService $entitlements, CampaignService $service): JsonResponse
    {
        Gate::authorize('manageCampaigns', $workspace);
        if (! $entitlements->canCreateCampaign($workspace)) {
            return $this->error(__('Campaigns are not included in your plan. Please upgrade.'), [], 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'whatsapp_account_id' => ['required', 'integer', Rule::exists('whatsapp_accounts', 'id')->where('workspace_id', $workspace->id)],
            'whatsapp_template_id' => ['required', 'integer', Rule::exists('whatsapp_templates', 'id')->where('workspace_id', $workspace->id)],
            'audience_type' => ['required', 'in:all,tag,segment,contacts'],
            'audience_config' => ['nullable', 'array'],
            'variable_mappings' => ['nullable', 'array'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        $account = $workspace->whatsappAccounts()->findOrFail($data['whatsapp_account_id']);
        $template = $workspace->templates()
            ->where('whatsapp_account_id', $account->id)
            ->find($data['whatsapp_template_id']);

        if (! $template) {
            return $this->error(__('The selected template belongs to a different WhatsApp account.'), [], 422);
        }
        if (! $template->isApproved()) {
            return $this->error(__('Campaigns can only use approved templates.'));
        }
        if (! $account->isConnected()) {
            return $this->error(__('The selected WhatsApp account is not connected.'), [], 422);
        }

        $campaign = Campaign::query()->create(collect($data)->except('scheduled_at')->all() + [
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'status' => CampaignStatus::Draft,
        ]);

        $preview = $service->previewAudience(
            $workspace,
            $account->id,
            $campaign->audience_type,
            $campaign->audience_config ?? [],
        );

        return $this->success(
            [
                'campaign' => $campaign,
                'audience_count' => $preview['eligible'],
                'audience_preview' => $preview,
            ],
            __('Campaign created.'),
            201,
        );
    }

    public function show(Request $request, Workspace $workspace, Campaign $campaign, CampaignService $service): JsonResponse
    {
        Gate::authorize('view', $workspace);
        abort_unless($campaign->workspace_id === $workspace->id, 404);
        $campaign->load(['template', 'whatsappAccount:id,display_phone_number']);

        $preview = $campaign->status === CampaignStatus::Draft
            ? $service->previewAudience(
                $workspace,
                $campaign->whatsapp_account_id,
                $campaign->audience_type,
                $campaign->audience_config ?? [],
            )
            : null;

        return $this->success([
            'campaign' => $campaign,
            'audience_count' => $preview['eligible'] ?? $campaign->total_recipients,
            'audience_preview' => $preview,
            'analytics' => $this->analytics($campaign),
        ]);
    }

    public function duplicate(Request $request, Workspace $workspace, Campaign $campaign): JsonResponse
    {
        Gate::authorize('manageCampaigns', $workspace);
        abort_unless($campaign->workspace_id === $workspace->id, 404);

        $copy = Campaign::query()->create([
            'workspace_id' => $workspace->id,
            'whatsapp_account_id' => $campaign->whatsapp_account_id,
            'whatsapp_template_id' => $campaign->whatsapp_template_id,
            'created_by' => $request->user()->id,
            'name' => $campaign->name.' (Copy)',
            'status' => CampaignStatus::Draft,
            'audience_type' => $campaign->audience_type,
            'audience_config' => $campaign->audience_config,
            'variable_mappings' => $campaign->variable_mappings,
        ]);

        return $this->success($copy, __('Campaign duplicated.'), 201);
    }

    public function schedule(Request $request, Workspace $workspace, Campaign $campaign, CampaignService $service, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('manageCampaigns', $workspace);
        abort_unless($campaign->workspace_id === $workspace->id, 404);

        if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Paused], true)) {
            return $this->error(__('Only draft or paused campaigns can be scheduled.'));
        }

        if (! $campaign->template?->isApproved() || ! $campaign->whatsappAccount?->isConnected()
            || $campaign->template->whatsapp_account_id !== $campaign->whatsapp_account_id) {
            return $this->error(__('Campaign account/template configuration is no longer valid.'), [], 422);
        }

        $preview = $service->previewAudience(
            $workspace,
            $campaign->whatsapp_account_id,
            $campaign->audience_type,
            $campaign->audience_config ?? [],
        );
        if ($preview['eligible'] === 0) {
            return $this->error(__('No contacts are currently eligible to receive this WhatsApp campaign.'), $preview, 422);
        }

        $data = $request->validate([
            'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
        ]);

        $service->schedule($campaign, isset($data['scheduled_at']) ? new \DateTime($data['scheduled_at']) : null);
        $audit->log('campaign.send', $workspace, $request->user(), $campaign, [
            'scheduled_at' => $data['scheduled_at'] ?? 'now',
            'eligible_recipients' => $preview['eligible'],
            'suppressed_recipients' => $preview['blocked_opt_out'] + $preview['blocked_no_consent'],
        ]);

        return $this->success($campaign->fresh(), __('Campaign scheduled.'));
    }

    public function pause(Request $request, Workspace $workspace, Campaign $campaign): JsonResponse
    {
        Gate::authorize('manageCampaigns', $workspace);
        abort_unless($campaign->workspace_id === $workspace->id, 404);
        if ($campaign->status !== CampaignStatus::Processing) {
            return $this->error(__('Only processing campaigns can be paused.'));
        }
        $campaign->update(['status' => CampaignStatus::Paused]);

        return $this->success($campaign, __('Campaign paused.'));
    }

    public function resume(Request $request, Workspace $workspace, Campaign $campaign, CampaignService $service): JsonResponse
    {
        Gate::authorize('manageCampaigns', $workspace);
        abort_unless($campaign->workspace_id === $workspace->id, 404);
        if ($campaign->status !== CampaignStatus::Paused) {
            return $this->error(__('Only paused campaigns can be resumed.'));
        }
        $service->resume($campaign);

        return $this->success($campaign->fresh(), __('Campaign resumed.'));
    }

    public function cancel(Request $request, Workspace $workspace, Campaign $campaign, CampaignService $service): JsonResponse
    {
        Gate::authorize('manageCampaigns', $workspace);
        abort_unless($campaign->workspace_id === $workspace->id, 404);
        if (in_array($campaign->status, [CampaignStatus::Completed, CampaignStatus::Cancelled], true)) {
            return $this->error(__('This campaign is already finished.'));
        }
        $service->cancel($campaign);

        return $this->success($campaign->fresh(), __('Campaign cancelled.'));
    }

    public function recipients(Request $request, Workspace $workspace, Campaign $campaign): JsonResponse
    {
        Gate::authorize('view', $workspace);
        abort_unless($campaign->workspace_id === $workspace->id, 404);

        $recipients = $campaign->recipients()
            ->with([
                'contact:id,first_name,last_name,display_name,phone_number,opt_in_status',
                'message:id,status,delivered_at,read_at,error_code,error_message',
            ])
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest('id')->paginate(min((int) $request->query('per_page', 50), 200));

        return $this->success([
            'items' => $recipients->items(),
            'meta' => [
                'current_page' => $recipients->currentPage(),
                'last_page' => $recipients->lastPage(),
                'total' => $recipients->total(),
            ],
        ]);
    }

    protected function analytics(Campaign $campaign): array
    {
        $sent = max(0, $campaign->sent_count);
        $total = max(0, $campaign->total_recipients);

        return [
            'delivery_rate' => $sent > 0 ? round(($campaign->delivered_count / $sent) * 100, 1) : 0.0,
            'read_rate' => $sent > 0 ? round(($campaign->read_count / $sent) * 100, 1) : 0.0,
            'failure_rate' => $total > 0 ? round(($campaign->failed_count / $total) * 100, 1) : 0.0,
            'suppressed_count' => $campaign->recipients()->where('status', 'skipped')->count(),
        ];
    }
}
