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
        Schema::create('financial_aid_document', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_aid_id')->constrained('financial_aid')->onDelete('cascade');
            $table->string('document_type'); // e.g., 'permit', 'certificate', 'license'
            $table->string('document_path'); // File path/URL
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_aid_document');
    }
};
