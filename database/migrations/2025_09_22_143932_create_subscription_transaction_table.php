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
        Schema::create('subscription_transaction', function (Blueprint $table) {
            $table->id('sub_transaction_id');
            $table->unsignedBigInteger('old_plan_id')->nullable();
            $table->unsignedBigInteger('new_plan_id');
            $table->string('payment_method', 50)->nullable();
            $table->decimal('amount_paid', 10, 2);
            $table->timestamp('transaction_date')->useCurrent();
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->foreign('old_plan_id')->references('plan_id')->on('subscription_plan')->onDelete('set null');
            $table->foreign('new_plan_id')->references('plan_id')->on('subscription_plan')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_transaction');
    }
};
