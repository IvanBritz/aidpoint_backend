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
        Schema::create('liquidation_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('liquidation_id')->constrained('liquidations')->onDelete('cascade');
            
            // File details
            $table->string('original_filename'); // Original filename when uploaded
            $table->string('stored_filename'); // Filename as stored on disk
            $table->string('file_path'); // Full path to the stored file
            $table->string('mime_type'); // MIME type (image/jpeg, application/pdf, etc.)
            $table->bigInteger('file_size'); // File size in bytes
            
            // Upload metadata
            $table->foreignId('uploaded_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('uploaded_at')->useCurrent();
            
            $table->timestamps();
            
            // Indexes for common queries
            $table->index(['liquidation_id']);
            $table->index(['uploaded_by', 'uploaded_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('liquidation_receipts');
    }
};