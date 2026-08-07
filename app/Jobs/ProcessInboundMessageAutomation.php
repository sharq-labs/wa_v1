<?php

namespace App\Jobs;

use App\Enums\AutomationState;
use App\Enums\MessageSenderType;
use App\Models\Automation;
use App\Models\AutomationWait;
use App\Models\Message;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\TriggerMatcher;
use App\Services\Messaging\MessageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Automation entry point for every inbound message:
 *  1. a pending reply-wait on the conversation consumes the message
 *  2. otherwise, if the bot is active, trigger matching runs
 *  3. otherwise nothing happens (bot paused = humans own the conversation)
 */
class ProcessInboundMessageAutomation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $messageId,
        public readonly bool $isNewContact = false,
    ) {
        $this->onQueue('automations');
    }

    public function handle(AutomationEngine $engine, TriggerMatcher $matcher, MessageService $messages): void
    {
        $message = Message::query()
            ->with(['conversation.workspace', 'contact'])
            ->find($this->messageId);

        if (! $message || ! $message->conversation) {
            return;
        }

        $conversation = $message->conversation;
        $workspace = $conversation->workspace;

        // 1. Waiting question / buttons consume the reply — even when the bot
        //    was paused after the wait was created the flow should finish.
        $wait = AutomationWait::query()
            ->where('conversation_id', $conversation->id)
            ->where('wait_type', AutomationWait::TYPE_REPLY)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if ($wait) {
            $engine->resumeReply($wait, $message);

            return;
        }

        // 2. Bot paused: never auto-respond.
        if (! $conversation->isBotActive()) {
            return;
        }

        $matches = $matcher->match($workspace, $message, $this->isNewContact);

        if ($matches->isEmpty()) {
            $this->handleFallback($workspace, $message, $engine, $messages);

            return;
        }

        $allowMultiple = (bool) $workspace->setting('automation.allow_multiple', false);

        foreach ($allowMultiple ? $matches : $matches->take(1) as $automation) {
            $engine->start($automation, $message->contact, $conversation, $message);
        }
    }

    protected function handleFallback($workspace, Message $message, AutomationEngine $engine, MessageService $messages): void
    {
        $mode = $workspace->setting('automation.fallback_mode', 'none');

        if ($mode === 'message') {
            $text = (string) $workspace->setting('automation.fallback_message', '');

            if ($text !== '' && $message->conversation) {
                $messages->sendText($message->conversation, $text, [
                    'sender_type' => MessageSenderType::Bot,
                    'sync' => true,
                ]);
            }

            return;
        }

        if ($mode === 'automation') {
            $automationId = $workspace->setting('automation.fallback_automation_id');
            $automation = $automationId
                ? Automation::query()->forWorkspace($workspace)
                    ->where('status', AutomationState::Published)
                    ->find($automationId)
                : null;

            if ($automation) {
                $engine->start($automation, $message->contact, $message->conversation, $message);
            }
        }
    }
}
