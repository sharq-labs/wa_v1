<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'contact_id' => Contact::factory(),
            'status' => 'open',
            'automation_status' => 'active',
            'opened_at' => now(),
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ];
    }
}
