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
        Schema::table('disbursements', function (Blueprint $table) {
            // Add liquidation tracking to disbursements
            $table->decimal('liquidated_amount', 12, 2)->default(0)->after('amount'); // How much has been liquidated so far
            $table->decimal('remaining_to_liquidate', 12, 2)->default(0)->after('liquidated_amount'); // How much still needs to be liquidated
            $table->boolean('fully_liquidated')->default(false)->after('remaining_to_liquidate'); // Whether this disbursement is fully liquidated
            $table->timestamp('fully_liquidated_at')->nullable()->after('fully_liquidated'); // When liquidation was completed
            
            // Add index for liquidation queries
            $table->index(['fully_liquidated', 'beneficiary_received_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('disbursements', function (Blueprint $table) {
            $table->dropIndex(['fully_liquidated', 'beneficiary_received_at']);
            $table->dropColumn([
                'liquidated_amount',
                'remaining_to_liquidate', 
                'fully_liquidated',
                'fully_liquidated_at'
            ]);
        });
    }
};