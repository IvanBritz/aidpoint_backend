<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_transaction', function (Blueprint $table) {
            if (!Schema::hasColumn('subscription_transaction', 'payment_intent_id')) {
                $table->string('payment_intent_id')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('subscription_transaction', 'status')) {
                $table->string('status', 30)->default('pending')->after('amount_paid');
            }
            if (!Schema::hasColumn('subscription_transaction', 'start_date')) {
                $table->date('start_date')->nullable()->after('status');
            }
            if (!Schema::hasColumn('subscription_transaction', 'end_date')) {
                $table->date('end_date')->nullable()->after('start_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subscription_transaction', function (Blueprint $table) {
            if (Schema::hasColumn('subscription_transaction', 'payment_intent_id')) {
                $table->dropColumn('payment_intent_id');
            }
            if (Schema::hasColumn('subscription_transaction', 'status')) {
                $table->dropColumn('status');
            }
            if (Schema::hasColumn('subscription_transaction', 'start_date')) {
                $table->dropColumn('start_date');
            }
            if (Schema::hasColumn('subscription_transaction', 'end_date')) {
                $table->dropColumn('end_date');
            }
        });
    }
};