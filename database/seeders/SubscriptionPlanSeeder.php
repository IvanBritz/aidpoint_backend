<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'plan_name' => 'Free',
                'price' => 0.00,
                'duration_in_months' => 1, // 1 month
                'description' => 'Free plan valid for 1 month.',
            ],
            [
                'plan_name' => 'Basic',
                'price' => 499.00, // adjust as needed
                'duration_in_months' => 24, // 2 years
                'description' => 'Basic plan valid for 2 years.',
            ],
            [
                'plan_name' => 'Premium',
                'price' => 1999.00, // adjust as needed
                'duration_in_months' => 60, // 5 years
                'description' => 'Premium plan valid for 5 years.',
            ],
            [
                'plan_name' => 'Test 30s',
                'price' => 10.00,
                // NOTE: schema stores months only; set 0 months so it expires same day for quick testing
                'duration_in_months' => 0,
                'description' => 'Test plan intended to expire within ~30 seconds in QA flows; use only for testing.',
            ],
        ];

        foreach ($plans as $data) {
            // Ensure idempotency by unique name, update details if it already exists
            SubscriptionPlan::updateOrCreate(
                ['plan_name' => $data['plan_name']],
                [
                    'price' => $data['price'],
                    'duration_in_months' => $data['duration_in_months'],
                    'description' => $data['description'],
                ]
            );
        }
    }
}
