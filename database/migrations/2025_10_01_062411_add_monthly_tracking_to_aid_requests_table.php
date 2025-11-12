<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('aid_requests', function (Blueprint $table) {
            // Add month and year fields to track the period for which aid is requested
            $table->unsignedTinyInteger('month')->nullable()->after('purpose')
                ->comment('Month for which the aid is requested (1-12, primarily for COLA)');
            $table->unsignedSmallInteger('year')->nullable()->after('month')
                ->comment('Year for which the aid is requested (primarily for COLA)');
            
            // Add index for monthly restriction queries
            $table->index(['beneficiary_id', 'fund_type', 'month', 'year'], 'monthly_restriction_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('aid_requests', function (Blueprint $table) {
            // Drop index first
            $table->dropIndex('monthly_restriction_idx');
            
            // Drop the added columns
            $table->dropColumn(['month', 'year']);
        });
    }
};
