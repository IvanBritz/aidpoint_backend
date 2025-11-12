<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Initialize liquidation tracking for existing disbursements
        DB::statement('
            UPDATE disbursements 
            SET 
                liquidated_amount = COALESCE(liquidated_amount, 0),
                remaining_to_liquidate = COALESCE(remaining_to_liquidate, amount),
                fully_liquidated = COALESCE(fully_liquidated, FALSE)
            WHERE 
                liquidated_amount IS NULL 
                OR remaining_to_liquidate IS NULL 
                OR fully_liquidated IS NULL
        ');

        // For disbursements that are beneficiary_received, set remaining_to_liquidate = amount if not set
        DB::statement("
            UPDATE disbursements 
            SET remaining_to_liquidate = amount 
            WHERE status = 'beneficiary_received' 
            AND (remaining_to_liquidate IS NULL OR remaining_to_liquidate = 0)
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This migration initializes data, so there's no meaningful rollback
        // The down migration would potentially cause data loss
    }
};