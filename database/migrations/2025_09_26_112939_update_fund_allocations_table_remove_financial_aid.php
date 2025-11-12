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
        Schema::table('fund_allocations', function (Blueprint $table) {
            // Drop the foreign key constraint first
            $table->dropForeign(['financial_aid_id']);
            // Drop the unique constraint
            $table->dropUnique(['financial_aid_id', 'fund_type', 'fund_name']);
            
            // Remove financial_aid_id and add user_id
            $table->dropColumn('financial_aid_id');
            $table->unsignedBigInteger('user_id')->after('id');
            
            // Rename fund_name to sponsor_name
            $table->renameColumn('fund_name', 'sponsor_name');
            
            // Add foreign key for user_id
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            
            // Add new unique constraint
            $table->unique(['user_id', 'fund_type', 'sponsor_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fund_allocations', function (Blueprint $table) {
            // Drop the foreign key constraint and unique constraint
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id', 'fund_type', 'sponsor_name']);
            
            // Remove user_id and add back financial_aid_id
            $table->dropColumn('user_id');
            $table->unsignedBigInteger('financial_aid_id')->after('id');
            
            // Rename sponsor_name back to fund_name
            $table->renameColumn('sponsor_name', 'fund_name');
            
            // Add back foreign key for financial_aid_id
            $table->foreign('financial_aid_id')->references('id')->on('financial_aid')->onDelete('cascade');
            
            // Add back original unique constraint
            $table->unique(['financial_aid_id', 'fund_type', 'fund_name']);
        });
    }
};
