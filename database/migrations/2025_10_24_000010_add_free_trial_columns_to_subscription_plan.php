<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plan', function (Blueprint $table) {
            if (!Schema::hasColumn('subscription_plan', 'is_free_trial')) {
                $table->boolean('is_free_trial')->default(false)->after('description');
            }
            if (!Schema::hasColumn('subscription_plan', 'trial_seconds')) {
                $table->unsignedInteger('trial_seconds')->nullable()->after('is_free_trial');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plan', function (Blueprint $table) {
            if (Schema::hasColumn('subscription_plan', 'trial_seconds')) {
                $table->dropColumn('trial_seconds');
            }
            if (Schema::hasColumn('subscription_plan', 'is_free_trial')) {
                $table->dropColumn('is_free_trial');
            }
        });
    }
};