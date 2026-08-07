<?php

namespace App\Jobs;

use App\Enums\AutomationStatus;
use App\Enums\CampaignStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageSenderType;
use App\Enums\MessageStatus;
use App\Models\CampaignRecipient;
use App\Models\Conversation;
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

    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $recipientId)
    {
        $this->onQueue('campaigns');
    }

    public function handle(MessageService $messages, TemplateRenderer $renderer): void
    {
        $recipient = CampaignRecipient::query()
            ->with(['campaign.template', 'campaign.whatsappAccount', 'contact'])
            ->find($this->recipientId);

        if (! $recipient || $recipient->status !== 'pending') {
            return;
        }

        $campaign = $recipient->campaign;

        if (! $campaign || $campaign->status === CampaignStatus::Cancelled) {
            $recipient->update(['status' => 'skipped']);

            return;
        }

        // Paused is reversible: drop this job but leave the recipient pending so
        // resuming re-queues them. Marking them skipped here would silently burn
        // the remaining audience the moment someone hit pause.
        if ($campaign->status === CampaignStatus::Paused) {
            return;
        }

        $contact = $recipient->contact;
        $template = $campaign->template;

        if (! $contact || ! $template || ! $template->isApproved()) {
            $recipient->update(['status' => 'failed', 'error_message' => 'Missing contact or unapproved template.']);
            $campaign->increment('failed_count');

            return;
        }

        if ($contact->opt_in_status === 'opted_out') {
            $recipient->update(['status' => 'skipped', 'error_message' => 'Contact opted out.']);

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

        $rendered = $renderer->render($template, $conversation, $campaign->variable_mappings ?? []);

        $message = $messages->sendTemplate($conversation, $template, $rendered['components'], $rendered['text'], [
            'sender_type' => MessageSenderType::System,
            'sync' => true,
        ]);

        $message->refresh();

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

        // Completion check.
        $pending = $campaign->recipients()->where('status', 'pending')->exists();

        if (! $pending && $campaign->status === CampaignStatus::Processing) {
            $campaign->update(['status' => CampaignStatus::Completed, 'completed_at' => now()]);
        }
    }
}
