<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('fund_allocations', 'financial_aid_id')) {
            Schema::table('fund_allocations', function (Blueprint $table) {
                $table->unsignedBigInteger('financial_aid_id')->nullable()->after('id');
            });

            // Try to backfill from users.financial_aid_id if a legacy user_id column exists
            if (Schema::hasColumn('fund_allocations', 'user_id')) {
                DB::statement(
                    'UPDATE fund_allocations SET financial_aid_id = (
                        SELECT financial_aid_id FROM users WHERE users.id = fund_allocations.user_id LIMIT 1
                    ) WHERE financial_aid_id IS NULL'
                );
            }

            // Create an index to speed up facility-wide queries
            try {
                Schema::table('fund_allocations', function (Blueprint $table) {
                    $table->index('financial_aid_id', 'fund_allocations_financial_aid_id_index');
                });
            } catch (\Throwable $e) {
                // Ignore if index already exists or SQLite limitations
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fund_allocations', 'financial_aid_id')) {
            Schema::table('fund_allocations', function (Blueprint $table) {
                $table->dropIndex('fund_allocations_financial_aid_id_index');
                $table->dropColumn('financial_aid_id');
            });
        }
    }
};