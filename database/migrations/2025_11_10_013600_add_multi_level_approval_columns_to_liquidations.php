<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            // Caseworker approval columns
            $table->unsignedBigInteger('caseworker_approved_by')->nullable()->after('reviewed_at');
            $table->text('caseworker_notes')->nullable()->after('caseworker_approved_by');
            $table->timestamp('caseworker_approved_at')->nullable()->after('caseworker_notes');

            // Finance approval columns
            $table->unsignedBigInteger('finance_approved_by')->nullable()->after('caseworker_approved_at');
            $table->text('finance_notes')->nullable()->after('finance_approved_by');
            $table->timestamp('finance_approved_at')->nullable()->after('finance_notes');

            // Director approval columns
            $table->unsignedBigInteger('director_approved_by')->nullable()->after('finance_approved_at');
            $table->text('director_notes')->nullable()->after('director_approved_by');
            $table->timestamp('director_approved_at')->nullable()->after('director_notes');

            // Rejection details
            $table->string('rejected_at_level')->nullable()->after('director_approved_at');
            $table->text('rejection_reason')->nullable()->after('rejected_at_level');

            // Optional FKs (null on delete)
            $table->foreign('caseworker_approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('finance_approved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('director_approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            $table->dropForeign(['caseworker_approved_by']);
            $table->dropForeign(['finance_approved_by']);
            $table->dropForeign(['director_approved_by']);
            $table->dropColumn([
                'caseworker_approved_by',
                'caseworker_notes',
                'caseworker_approved_at',
                'finance_approved_by',
                'finance_notes',
                'finance_approved_at',
                'director_approved_by',
                'director_notes',
                'director_approved_at',
                'rejected_at_level',
                'rejection_reason',
            ]);
        });
    }
};
