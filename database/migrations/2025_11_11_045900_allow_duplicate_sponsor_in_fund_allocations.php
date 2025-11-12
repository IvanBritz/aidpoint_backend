<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure there is a standalone index for the foreign key column so we can drop any
        // composite UNIQUE index that MySQL might currently be using for the FK.
        // Add a dedicated index for financial_aid_id if not present (so FK has its own index)
        try {
            $idx = DB::select("SHOW INDEX FROM `fund_allocations` WHERE Key_name = 'fund_allocations_financial_aid_id_index'");
            if (empty($idx)) {
                DB::statement('ALTER TABLE `fund_allocations` ADD INDEX `fund_allocations_financial_aid_id_index`(`financial_aid_id`)');
            }
        } catch (\Throwable $e) {}

        // Try to drop all possible unique indexes that enforce (facility, type, sponsor) uniqueness.
        // We use raw SQL to avoid MySQL complaining that the index is required by a FK.
        $dropIndexCandidates = [
            'fund_allocations_financial_aid_id_fund_type_sponsor_name_unique',
            'fund_allocations_user_id_fund_type_sponsor_name_unique',
            // legacy when column was fund_name instead of sponsor_name
            'fund_allocations_financial_aid_id_fund_type_fund_name_unique',
            'fund_allocations_user_id_fund_type_fund_name_unique',
        ];
        foreach ($dropIndexCandidates as $idx) {
            try { DB::statement("ALTER TABLE `fund_allocations` DROP INDEX `{$idx}`"); } catch (\Throwable $e) {}
        }

        // Fallback attempts via Schema builder (no-op if not present)
        try { Schema::table('fund_allocations', function (Blueprint $table) { $table->dropUnique(['financial_aid_id', 'fund_type', 'sponsor_name']); }); } catch (\Throwable $e) {}
        try { Schema::table('fund_allocations', function (Blueprint $table) { $table->dropUnique(['user_id', 'fund_type', 'sponsor_name']); }); } catch (\Throwable $e) {}
        try { Schema::table('fund_allocations', function (Blueprint $table) { $table->dropUnique(['financial_aid_id', 'fund_type', 'fund_name']); }); } catch (\Throwable $e) {}
        try { Schema::table('fund_allocations', function (Blueprint $table) { $table->dropUnique(['user_id', 'fund_type', 'fund_name']); }); } catch (\Throwable $e) {}

        // Add a non-unique composite index for performance (create with whichever column exists)
        if (Schema::hasColumn('fund_allocations', 'sponsor_name')) {
            try {
                $idx = DB::select("SHOW INDEX FROM `fund_allocations` WHERE Key_name = 'fund_allocations_facility_type_sponsor_index'");
                if (empty($idx)) {
                    DB::statement('ALTER TABLE `fund_allocations` ADD INDEX `fund_allocations_facility_type_sponsor_index`(`financial_aid_id`, `fund_type`, `sponsor_name`)');
                }
            } catch (\Throwable $e) {}
        }
        if (Schema::hasColumn('fund_allocations', 'fund_name')) {
            try {
                $idx = DB::select("SHOW INDEX FROM `fund_allocations` WHERE Key_name = 'fund_allocations_facility_type_fund_index'");
                if (empty($idx)) {
                    DB::statement('ALTER TABLE `fund_allocations` ADD INDEX `fund_allocations_facility_type_fund_index`(`financial_aid_id`, `fund_type`, `fund_name`)');
                }
            } catch (\Throwable $e) {}
        }
    }

    public function down(): void
    {
        // Drop the non-unique indexes if present
        try { DB::statement('ALTER TABLE `fund_allocations` DROP INDEX `fund_allocations_facility_type_sponsor_index`'); } catch (\Throwable $e) {}
        try { DB::statement('ALTER TABLE `fund_allocations` DROP INDEX `fund_allocations_facility_type_fund_index`'); } catch (\Throwable $e) {}

        // Restore a unique constraint (best-effort)
        try { DB::statement('ALTER TABLE `fund_allocations` ADD UNIQUE `fund_allocations_financial_aid_id_fund_type_sponsor_name_unique` (`financial_aid_id`, `fund_type`, `sponsor_name`)'); } catch (\Throwable $e) {}
    }
};
