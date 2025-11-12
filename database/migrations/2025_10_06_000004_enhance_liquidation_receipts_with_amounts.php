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
        Schema::table('liquidation_receipts', function (Blueprint $table) {
            // Add individual receipt tracking
            $table->decimal('receipt_amount', 12, 2)->after('liquidation_id'); // Amount represented by this specific receipt
            $table->string('receipt_number')->nullable()->after('receipt_amount'); // OR/Invoice number from the receipt
            $table->date('receipt_date')->after('receipt_number'); // Date on the receipt
            $table->text('description')->nullable()->after('receipt_date'); // Description of what this receipt is for
            
            // Receipt verification status
            $table->enum('verification_status', ['pending', 'verified', 'questioned'])->default('pending')->after('description');
            $table->text('verification_notes')->nullable()->after('verification_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('liquidation_receipts', function (Blueprint $table) {
            $table->dropColumn([
                'receipt_amount',
                'receipt_number', 
                'receipt_date',
                'description',
                'verification_status',
                'verification_notes'
            ]);
        });
    }
};