<?php

namespace Database\Factories;

use App\Models\WhatsAppAccount;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class WhatsAppAccountFactory extends Factory
{
    protected $model = WhatsAppAccount::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'provider' => 'fake',
            'meta_business_id' => 'biz-'.Str::random(8),
            'waba_id' => 'waba-'.Str::random(8),
            'phone_number_id' => 'phone-'.Str::random(12),
            'display_phone_number' => '+20'.fake()->numerify('##########'),
            'verified_name' => fake()->company(),
            'access_token' => 'token-'.Str::random(32),
            'quality_rating' => 'GREEN',
            'messaging_limit' => 'TIER_1K',
            'status' => 'connected',
        ];
    }
}
