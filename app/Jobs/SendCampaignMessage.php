<?php

namespace App\Jobs;

use App\Enums\AutomationStatus;
use App\Enums\CampaignStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Models\CampaignRecipient;
use App\Models\Conversation;
use App\Services\Campaigns\CampaignPolicyService;
use App\Services\Campaigns\CampaignService;
use App\Services\Messaging\MessageService;
use App\Services\Templates\TemplateRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCampaignMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $recipientId)
    {
        $this->onQueue('campaigns');
    }

    public function handle(
        MessageService $messages,
        TemplateRenderer $renderer,
        CampaignService $campaigns,
        CampaignPolicyService $policy,
    ): void {
        // Read state before claiming so a quiet-hours delay leaves the recipient
        // pending and another duplicate job can still safely race later.
        $previewRecipient = CampaignRecipient::query()
            ->with(['campaign.workspace', 'campaign.template'])
            ->find($this->recipientId);

        if (! $previewRecipient || $previewRecipient->status !== 'pending') {
            return;
        }

        $previewCampaign = $previewRecipient->campaign;
        if (! $previewCampaign || $previewCampaign->status === CampaignStatus::Cancelled) {
            $previewRecipient->update(['status' => 'skipped']);

            return;
        }

        if ($previewCampaign->status === CampaignStatus::Paused) {
            return;
        }

        $quietDelay = $policy->quietHoursDelaySeconds($previewCampaign->workspace);
        if ($quietDelay > 0) {
            $this->release($quietDelay);

            return;
        }

        $claimed = CampaignRecipient::query()
            ->whereKey($this->recipientId)
            ->where('status', 'pending')
            ->update(['status' => 'processing']);

        if ($claimed !== 1) {
            return;
        }

        $recipient = CampaignRecipient::query()
            ->with(['campaign.template', 'campaign.whatsappAccount', 'campaign.workspace', 'contact'])
            ->find($this->recipientId);

        if (! $recipient) {
            return;
        }

        $campaign = $recipient->campaign;
        if (! $campaign || $campaign->status === CampaignStatus::Cancelled) {
            $recipient->update(['status' => 'skipped']);

            return;
        }

        if ($campaign->status === CampaignStatus::Paused) {
            $recipient->update(['status' => 'pending']);

            return;
        }

        $contact = $recipient->contact;
        $template = $campaign->template;

        if (! $contact || ! $template || ! $template->isApproved()
            || $template->whatsapp_account_id !== $campaign->whatsapp_account_id) {
            $recipient->update(['status' => 'failed', 'error_message' => 'Missing contact, invalid account binding, or unapproved template.']);
            $campaign->increment('failed_count');
            $this->completeIfFinished($campaign);

            return;
        }

        if (! $campaigns->isContactEligible($campaign, $contact)) {
            $reason = $contact->opt_in_status === 'opted_out'
                ? 'Contact opted out.'
                : (! $policy->isFrequencyAllowed($campaign, $contact)
                    ? 'Marketing frequency cap reached.'
                    : 'No WhatsApp opt-in outside the 24-hour window.');

            $recipient->update([
                'status' => 'skipped',
                'error_message' => $reason,
            ]);
            $this->completeIfFinished($campaign);

            return;
        }

        $conversation = Conversation::query()->firstOrCreate(
            [
                'workspace_id' => $campaign->workspace_id,
                'whatsapp_account_id' => $campaign->whatsapp_account_id,
                'contact_id' => $contact->id,
            ],
            [
                'status' => ConversationStatus::Open,
                'automation_status' => AutomationStatus::Active,
                'opened_at' => now(),
            ],
        );

        try {
            $rendered = $renderer->render($template, $conversation, $campaign->variable_mappings ?? []);
            $message = $messages->sendTemplate($conversation, $template, $rendered['components'], $rendered['text'], [
                'sender_type' => MessageSenderType::System,
                'sync' => true,
            ])->refresh();
        } catch (\Throwable $e) {
            $recipient->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 2000),
            ]);
            $campaign->increment('failed_count');
            report($e);
            $this->completeIfFinished($campaign);

            return;
        }

        if ($message->status === MessageStatus::Failed) {
            $recipient->update([
                'status' => 'failed',
                'message_id' => $message->id,
                'error_message' => $message->error_message,
            ]);
            $campaign->increment('failed_count');
        } else {
            $recipient->update([
                'status' => 'sent',
                'message_id' => $message->id,
                'sent_at' => now(),
            ]);
            $campaign->increment('sent_count');
        }

        $this->completeIfFinished($campaign);
    }

    protected function completeIfFinished($campaign): void
    {
        $unfinished = $campaign->recipients()
            ->whereIn('status', ['pending', 'processing'])
            ->exists();

        if (! $unfinished && $campaign->status === CampaignStatus::Processing) {
            $campaign->update(['status' => CampaignStatus::Completed, 'completed_at' => now()]);
        }
    }
}
