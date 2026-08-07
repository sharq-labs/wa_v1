<?php

namespace Database\Factories;

use App\Models\WhatsAppAccount;
use App\Models\WhatsAppTemplate;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WhatsAppTemplateFactory extends Factory
{
    protected $model = WhatsAppTemplate::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'whatsapp_account_id' => WhatsAppAccount::factory(),
            'name' => Str::snake(fake()->unique()->words(2, true)),
            'language' => 'en',
            'category' => 'MARKETING',
            'status' => 'approved',
            'body' => 'Hello {{1}}, thanks for reaching out.',
            'variables' => ['1' => 'Ahmed'],
        ];
    }
}
