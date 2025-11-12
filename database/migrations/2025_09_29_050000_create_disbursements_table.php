<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disbursements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aid_request_id')->constrained('aid_requests')->onDelete('cascade');
            $table->decimal('amount', 12, 2);
            $table->string('reference_no')->nullable();
            $table->text('notes')->nullable();
            
            // Disbursement status tracking
            $table->enum('status', [
                'finance_disbursed', 
                'caseworker_received', 
                'caseworker_disbursed', 
                'beneficiary_received'
            ])->default('finance_disbursed');
            
            // Finance disbursement fields
            $table->foreignId('finance_disbursed_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('finance_disbursed_at')->useCurrent();
            
            // Caseworker receipt fields
            $table->foreignId('caseworker_received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('caseworker_received_at')->nullable();
            
            // Caseworker disbursement fields
            $table->foreignId('caseworker_disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('caseworker_disbursed_at')->nullable();
            
            // Beneficiary receipt fields
            $table->timestamp('beneficiary_received_at')->nullable();
            
            $table->timestamps();
            
            // Indexes for common queries
            $table->index(['status']);
            $table->index(['aid_request_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disbursements');
    }
};