<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('aid_requests', function (Blueprint $table) {
            // Track the approval stage and stage decisions
            $table->string('stage', 20)->default('caseworker')->after('review_notes');

            // Finance review fields
            $table->enum('finance_decision', ['pending', 'approved', 'rejected'])->default('pending')->after('stage');
            $table->foreignId('finance_reviewed_by')->nullable()->after('finance_decision')->constrained('users')->nullOnDelete();
            $table->timestamp('finance_reviewed_at')->nullable()->after('finance_reviewed_by');
            $table->text('finance_notes')->nullable()->after('finance_reviewed_at');

            // Director (final) review fields
            $table->enum('director_decision', ['pending', 'approved', 'rejected'])->default('pending')->after('finance_notes');
            $table->foreignId('director_reviewed_by')->nullable()->after('director_decision')->constrained('users')->nullOnDelete();
            $table->timestamp('director_reviewed_at')->nullable()->after('director_reviewed_by');
            $table->text('director_notes')->nullable()->after('director_reviewed_at');

            // Indexes for common queries
            $table->index(['stage', 'finance_decision']);
            $table->index(['stage', 'director_decision']);
        });
    }

    public function down(): void
    {
        Schema::table('aid_requests', function (Blueprint $table) {
            $table->dropIndex(['stage', 'finance_decision']);
            $table->dropIndex(['stage', 'director_decision']);

            $table->dropColumn([
                'stage',
                'finance_decision', 'finance_reviewed_by', 'finance_reviewed_at', 'finance_notes',
                'director_decision', 'director_reviewed_by', 'director_reviewed_at', 'director_notes',
            ]);
        });
    }
};