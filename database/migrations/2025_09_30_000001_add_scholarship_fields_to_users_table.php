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
        Schema::table('users', function (Blueprint $table) {
            // Add scholarship status field for COLA calculation
            $table->boolean('is_scholar')->default(false)->after('school_year');
            
            // Add financial aid facility relationship (if not exists)
            if (!Schema::hasColumn('users', 'financial_aid_id')) {
                $table->foreignId('financial_aid_id')->nullable()->after('is_scholar')
                      ->constrained('financial_aid')->nullOnDelete();
            }
            
            // Add caseworker assignment field (if not exists) 
            if (!Schema::hasColumn('users', 'caseworker_id')) {
                $table->foreignId('caseworker_id')->nullable()->after('financial_aid_id')
                      ->constrained('users')->nullOnDelete();
            }
            
            // Index for common queries
            $table->index(['is_scholar', 'systemrole_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_scholar', 'systemrole_id']);
            
            if (Schema::hasColumn('users', 'caseworker_id')) {
                $table->dropForeign(['caseworker_id']);
                $table->dropColumn('caseworker_id');
            }
            
            if (Schema::hasColumn('users', 'financial_aid_id')) {
                $table->dropForeign(['financial_aid_id']);
                $table->dropColumn('financial_aid_id');
            }
            
            $table->dropColumn('is_scholar');
        });
    }
};