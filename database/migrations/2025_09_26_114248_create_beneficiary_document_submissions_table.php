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
        Schema::create('beneficiary_document_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('beneficiary_id'); // Link to beneficiary user
            $table->date('enrollment_date');
            $table->string('year_level'); // e.g., '1st Year', '2nd Year', etc.
            $table->string('enrollment_certification_path')->nullable(); // Path to uploaded enrollment cert photo
            $table->string('sao_photo_path')->nullable(); // Path to uploaded SAO photo
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedBigInteger('reviewed_by')->nullable(); // Caseworker who reviewed
            $table->text('review_notes')->nullable(); // Review comments
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            
            $table->foreign('beneficiary_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('reviewed_by')->references('id')->on('users')->onDelete('set null');
            $table->index(['beneficiary_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('beneficiary_document_submissions');
    }
};
