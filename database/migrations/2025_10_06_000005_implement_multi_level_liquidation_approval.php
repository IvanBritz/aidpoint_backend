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
        Schema::table('liquidations', function (Blueprint $table) {
            // Update status enum to include multi-level approval workflow
            $table->enum('status', [
                'pending',
                'in_progress', 
                'complete',
                'pending_caseworker_approval',
                'pending_finance_approval', 
                'pending_director_approval',
                'approved',
                'rejected'
            ])->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            // Restore old status enum (columns will be kept for now)
            $table->enum('status', ['pending', 'in_progress', 'complete', 'approved', 'rejected'])->default('pending')->change();
        });
    }
};