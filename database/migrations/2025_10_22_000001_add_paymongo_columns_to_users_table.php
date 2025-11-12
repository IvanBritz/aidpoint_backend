<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'paymongo_payment_method_id')) {
                $table->string('paymongo_payment_method_id')->nullable()->after('remember_token');
            }
            if (!Schema::hasColumn('users', 'paymongo_customer_id')) {
                $table->string('paymongo_customer_id')->nullable()->after('paymongo_payment_method_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'paymongo_payment_method_id')) {
                $table->dropColumn('paymongo_payment_method_id');
            }
            if (Schema::hasColumn('users', 'paymongo_customer_id')) {
                $table->dropColumn('paymongo_customer_id');
            }
        });
    }
};