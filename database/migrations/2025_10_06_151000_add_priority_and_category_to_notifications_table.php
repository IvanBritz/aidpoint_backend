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
        Schema::table('notifications', function (Blueprint $table) {
            // Check if columns already exist before adding them
            if (!Schema::hasColumn('notifications', 'priority')) {
                $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium')->after('data');
            }
            
            if (!Schema::hasColumn('notifications', 'category')) {
                $table->string('category')->default('general')->after('priority');
            }
            
            // Add indexes for better performance (check if they don't already exist)
            try {
                $table->index(['category', 'created_at']);
            } catch (\Exception $e) {
                // Index might already exist, skip
            }
            
            try {
                $table->index(['priority', 'created_at']);
            } catch (\Exception $e) {
                // Index might already exist, skip
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            // Drop indexes if they exist
            try {
                $table->dropIndex(['category', 'created_at']);
            } catch (\Exception $e) {
                // Index might not exist, skip
            }
            
            try {
                $table->dropIndex(['priority', 'created_at']);
            } catch (\Exception $e) {
                // Index might not exist, skip
            }
            
            $table->dropColumn(['priority', 'category']);
        });
    }
};