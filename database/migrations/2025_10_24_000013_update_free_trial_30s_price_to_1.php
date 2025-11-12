<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscription_plan')
            ->where('plan_name', 'Free Trial – 30 Seconds')
            ->update(['price' => 1, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('subscription_plan')
            ->where('plan_name', 'Free Trial – 30 Seconds')
            ->update(['price' => 20, 'updated_at' => now()]);
    }
};