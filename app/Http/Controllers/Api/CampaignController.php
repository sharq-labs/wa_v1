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
            ->with(['template:id,name,language', 'whatsappAccount:id,display_phone_number'])
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

        return $this->success(
            ['campaign' => $campaign, 'audience_count' => $service->audienceQuery($campaign)->count()],
            __('Campaign created.'),
            201,
        );
    }

    public function show(Request $request, Workspace $workspace, Campaign $campaign, CampaignService $service): JsonResponse
    {
        Gate::authorize('view', $workspace);
        abort_unless($campaign->workspace_id === $workspace->id, 404);
        $campaign->load(['template', 'whatsappAccount:id,display_phone_number']);

        return $this->success([
            'campaign' => $campaign,
            'audience_count' => $campaign->status === CampaignStatus::Draft
                ? $service->audienceQuery($campaign)->count()
                : $campaign->total_recipients,
        ]);
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

        $data = $request->validate([
            'scheduled_at' => ['nullable', 'date', 'after_or_equal:now'],
        ]);

        $service->schedule($campaign, isset($data['scheduled_at']) ? new \DateTime($data['scheduled_at']) : null);
        $audit->log('campaign.send', $workspace, $request->user(), $campaign, [
            'scheduled_at' => $data['scheduled_at'] ?? 'now',
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
            ->with('contact:id,first_name,last_name,display_name,phone_number')
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
}
