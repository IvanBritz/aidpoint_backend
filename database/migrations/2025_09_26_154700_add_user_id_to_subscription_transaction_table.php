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
        // Add user_id to subscription_transaction if it does not exist yet
        if (!Schema::hasColumn('subscription_transaction', 'user_id')) {
            Schema::table('subscription_transaction', function (Blueprint $table) {
                $table->unsignedBigInteger('user_id')->after('sub_transaction_id');
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('subscription_transaction', 'user_id')) {
            Schema::table('subscription_transaction', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            });
        }
    }
};
