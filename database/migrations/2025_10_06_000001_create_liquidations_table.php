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
        Schema::create('liquidations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('disbursement_id')->constrained('disbursements')->onDelete('cascade');
            $table->foreignId('beneficiary_id')->constrained('users')->onDelete('cascade');
            
            // Liquidation details
            $table->date('liquidation_date'); // Date of the expense/receipt
            $table->string('disbursement_type'); // tuition, cola, other
            $table->string('or_invoice_no'); // Official receipt or invoice number
            $table->decimal('amount', 12, 2); // Amount being liquidated
            $table->text('description')->nullable(); // Optional description/notes
            
            // Status and review
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reviewer_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            
            $table->timestamps();
            
            // Indexes for common queries
            $table->index(['disbursement_id', 'status']);
            $table->index(['beneficiary_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('liquidations');
    }
};