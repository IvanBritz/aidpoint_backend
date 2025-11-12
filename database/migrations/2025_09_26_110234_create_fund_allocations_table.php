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
        Schema::create('fund_allocations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('financial_aid_id'); // Link to financial aid center
            $table->enum('fund_type', ['tuition', 'cola', 'other']); // Type of fund
            $table->string('fund_name'); // Name/description of the fund
            $table->decimal('allocated_amount', 15, 2)->default(0); // Total allocated amount
            $table->decimal('utilized_amount', 15, 2)->default(0); // Amount already used
            $table->decimal('remaining_amount', 15, 2)->default(0); // Remaining amount
            $table->text('description')->nullable(); // Optional description
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->foreign('financial_aid_id')->references('id')->on('financial_aid')->onDelete('cascade');
            $table->unique(['financial_aid_id', 'fund_type', 'fund_name']); // Prevent duplicate fund types per center
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fund_allocations');
    }
};
