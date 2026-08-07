<?php

namespace App\Services\Messaging;

use App\Models\Conversation;
use Carbon\CarbonInterface;

/**
 * Central policy for what may be sent into a conversation right now.
 *
 * WhatsApp only allows free-form messages inside the customer service window
 * (measured from the last inbound customer message). Outside it, an approved
 * template is required. UI code must consult this service instead of
 * hard-coding the rule.
 */
class MessagingEligibilityService
{
    public function canSendFreeForm(Conversation $conversation): bool
    {
        if (! $conversation->last_inbound_at) {
            return false;
        }

        $windowHours = (int) config('whatsapp.service_window_hours', 24);

        return $conversation->last_inbound_at->gt(now()->subHours($windowHours));
    }

    public function windowExpiresAt(Conversation $conversation): ?CarbonInterface
    {
        if (! $conversation->last_inbound_at) {
            return null;
        }

        return $conversation->last_inbound_at->addHours((int) config('whatsapp.service_window_hours', 24));
    }

    /** @return array{can_send_free_form: bool, requires_template: bool, window_expires_at: ?string} */
    public function state(Conversation $conversation): array
    {
        $canFreeForm = $this->canSendFreeForm($conversation);

        return [
            'can_send_free_form' => $canFreeForm,
            'requires_template' => ! $canFreeForm,
            'window_expires_at' => $this->windowExpiresAt($conversation)?->toIso8601String(),
        ];
    }
}
