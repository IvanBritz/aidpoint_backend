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
        // Check if user_id column exists
        if (Schema::hasColumn('fund_allocations', 'user_id')) {
            // Migrate existing data: update user_id to point to facility instead of user
            DB::statement('
                UPDATE fund_allocations
                SET user_id = (
                    SELECT financial_aid_id 
                    FROM users 
                    WHERE users.id = fund_allocations.user_id
                    LIMIT 1
                )
                WHERE EXISTS (
                    SELECT 1 
                    FROM users 
                    WHERE users.id = fund_allocations.user_id 
                    AND users.financial_aid_id IS NOT NULL
                )
            ');
            
            Schema::table('fund_allocations', function (Blueprint $table) {
                // Drop the foreign key constraint and unique constraint first
                $table->dropForeign(['user_id']);
                $table->dropUnique(['user_id', 'fund_type', 'sponsor_name']);
                
                // Rename user_id column to financial_aid_id
                $table->renameColumn('user_id', 'financial_aid_id');
                
                // Add foreign key for financial_aid_id
                $table->foreign('financial_aid_id')->references('id')->on('financial_aid')->onDelete('cascade');
                
                // Add unique constraint to prevent duplicate fund types per center
                $table->unique(['financial_aid_id', 'fund_type', 'sponsor_name']);
            });
        }
        // If user_id doesn't exist, table is already in correct state or empty
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fund_allocations', function (Blueprint $table) {
            // Drop the foreign key constraint and unique constraint
            $table->dropForeign(['financial_aid_id']);
            $table->dropUnique(['financial_aid_id', 'fund_type', 'sponsor_name']);
            
            // Remove financial_aid_id and add back user_id
            $table->dropColumn('financial_aid_id');
            $table->unsignedBigInteger('user_id')->after('id');
            
            // Add back foreign key for user_id
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Add back unique constraint
            $table->unique(['user_id', 'fund_type', 'sponsor_name']);
        });
    }
};
