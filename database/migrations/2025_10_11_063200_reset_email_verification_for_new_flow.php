<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Reset email verification for users to implement the new post-registration verification flow
        // This will require all users to verify their email after their next login
        // Comment out the next line if you want to keep existing users verified
        // DB::table('users')->update(['email_verified_at' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Mark all users as verified if rolling back
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }
};