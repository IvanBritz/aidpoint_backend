<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_plan', function (Blueprint $table) {
            if (!Schema::hasColumn('subscription_plan', 'duration_in_days')) {
                $table->integer('duration_in_days')->default(0)->after('duration_in_months');
            }
            if (!Schema::hasColumn('subscription_plan', 'duration_in_seconds')) {
                $table->integer('duration_in_seconds')->default(0)->after('duration_in_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscription_plan', function (Blueprint $table) {
            if (Schema::hasColumn('subscription_plan', 'duration_in_seconds')) {
                $table->dropColumn('duration_in_seconds');
            }
            if (Schema::hasColumn('subscription_plan', 'duration_in_days')) {
                $table->dropColumn('duration_in_days');
            }
        });
    }
};
