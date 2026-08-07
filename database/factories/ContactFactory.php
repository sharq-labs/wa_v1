<?php

namespace Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactFactory extends Factory
{
    public function definition(): array
    {
        $phone = '20'.fake()->unique()->numerify('##########');

        return [
            'workspace_id' => Workspace::factory(),
            'wa_id' => $phone,
            'phone_number' => $phone,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'status' => 'active',
            'opt_in_status' => 'opted_in',
        ];
    }
}
