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
            // Remove the old amount field and replace with more specific tracking
            $table->dropColumn('amount');
            
            // Add disbursement amount tracking
            $table->decimal('total_disbursed_amount', 12, 2)->after('or_invoice_no'); // Total amount that was disbursed
            $table->decimal('total_receipt_amount', 12, 2)->default(0)->after('total_disbursed_amount'); // Sum of all receipt amounts
            $table->decimal('remaining_amount', 12, 2)->default(0)->after('total_receipt_amount'); // Amount still to be liquidated
            
            // Liquidation completion tracking
            $table->boolean('is_complete')->default(false)->after('remaining_amount'); // Whether total receipts match disbursed amount
            $table->timestamp('completed_at')->nullable()->after('is_complete'); // When liquidation was completed
            
            // Update status enum to include 'complete' status
            $table->enum('status', ['pending', 'in_progress', 'complete', 'approved', 'rejected'])->default('pending')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('liquidations', function (Blueprint $table) {
            // Restore old structure
            $table->decimal('amount', 12, 2)->after('or_invoice_no');
            
            // Drop new columns
            $table->dropColumn([
                'total_disbursed_amount',
                'total_receipt_amount', 
                'remaining_amount',
                'is_complete',
                'completed_at'
            ]);
            
            // Restore old status enum
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->change();
        });
    }
};