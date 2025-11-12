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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_first_login')->default(true)->after('email_verified_at');
            $table->string('login_verification_code')->nullable()->after('is_first_login');
            $table->timestamp('login_verification_code_expires_at')->nullable()->after('login_verification_code');
            $table->boolean('requires_login_verification')->default(false)->after('login_verification_code_expires_at');
            $table->timestamp('last_login_at')->nullable()->after('requires_login_verification');
            $table->integer('login_attempt_count')->default(0)->after('last_login_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'is_first_login',
                'login_verification_code',
                'login_verification_code_expires_at',
                'requires_login_verification',
                'last_login_at',
                'login_attempt_count'
            ]);
        });
    }
};