<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'conversation_id' => Conversation::factory(),
            'contact_id' => Contact::factory(),
            'provider_message_id' => 'wamid.'.Str::random(24),
            'direction' => 'inbound',
            'sender_type' => 'contact',
            'message_type' => 'text',
            'content' => fake()->sentence(),
            'status' => 'received',
        ];
    }
}
