<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'attrs' => [
                    'name' => 'Starter',
                    'slug' => 'starter',
                    'description' => 'For small businesses getting started with WhatsApp automation.',
                    'price_monthly' => 50000, // 500.00 EGP in minor units
                    'price_yearly' => 500000,
                    'currency' => 'EGP',
                    'is_default' => true,
                    'sort_order' => 1,
                ],
                'features' => [
                    'whatsapp_numbers' => '1',
                    'agents' => '2',
                    'contacts' => '2000',
                    'automations' => '10',
                    'automation_runs_monthly' => '5000',
                    'campaigns' => 'false',
                    'api_access' => 'false',
                    'advanced_analytics' => 'false',
                ],
            ],
            [
                'attrs' => [
                    'name' => 'Pro',
                    'slug' => 'pro',
                    'description' => 'For growing teams that need campaigns, analytics and API access.',
                    'price_monthly' => 150000,
                    'price_yearly' => 1500000,
                    'currency' => 'EGP',
                    'sort_order' => 2,
                ],
                'features' => [
                    'whatsapp_numbers' => '3',
                    'agents' => '10',
                    'contacts' => '20000',
                    'automations' => 'unlimited',
                    'automation_runs_monthly' => 'unlimited',
                    'campaigns' => 'true',
                    'api_access' => 'true',
                    'advanced_analytics' => 'true',
                ],
            ],
            [
                'attrs' => [
                    'name' => 'Agency',
                    'slug' => 'agency',
                    'description' => 'For agencies managing many numbers, agents and workspaces.',
                    'price_monthly' => 400000,
                    'price_yearly' => 4000000,
                    'currency' => 'EGP',
                    'sort_order' => 3,
                ],
                'features' => [
                    'whatsapp_numbers' => '10',
                    'agents' => '50',
                    'contacts' => '200000',
                    'automations' => 'unlimited',
                    'automation_runs_monthly' => 'unlimited',
                    'campaigns' => 'true',
                    'api_access' => 'true',
                    'advanced_analytics' => 'true',
                    'multiple_workspaces' => 'true',
                ],
            ],
        ];

        foreach ($plans as $plan) {
            $model = Plan::query()->updateOrCreate(
                ['slug' => $plan['attrs']['slug']],
                $plan['attrs'],
            );

            foreach ($plan['features'] as $key => $value) {
                $model->features()->updateOrCreate(['key' => $key], ['value' => $value]);
            }
        }
    }
}
