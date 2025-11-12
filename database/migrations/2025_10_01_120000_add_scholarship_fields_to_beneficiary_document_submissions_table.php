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
        Schema::table('beneficiary_document_submissions', function (Blueprint $table) {
            $table->boolean('is_scholar')->default(false)->after('year_level');
            $table->string('scholarship_certification_path')->nullable()->after('enrollment_certification_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('beneficiary_document_submissions', function (Blueprint $table) {
            $table->dropColumn(['is_scholar', 'scholarship_certification_path']);
        });
    }
};
