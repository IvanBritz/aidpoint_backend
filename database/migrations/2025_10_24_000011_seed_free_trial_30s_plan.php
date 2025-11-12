<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('subscription_plan')->where('plan_name', 'Free Trial – 30 Seconds')->exists();
        if (!$exists) {
            DB::table('subscription_plan')->insert([
                'plan_name' => 'Free Trial – 30 Seconds',
                'price' => 0,
                'duration_in_months' => 0,
                'description' => 'A one-time 30-second trial for directors.',
                'is_free_trial' => true,
                'trial_seconds' => 30,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('subscription_plan')->where('plan_name', 'Free Trial – 30 Seconds')->delete();
    }
};