<?php

namespace Database\Factories;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

class AutomationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->words(3, true),
            'status' => 'draft',
            'priority' => 0,
            'draft_definition' => ['nodes' => [], 'edges' => []],
        ];
    }
}
