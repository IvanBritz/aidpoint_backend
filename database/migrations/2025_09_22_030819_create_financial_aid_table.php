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
        Schema::create('financial_aid', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users'); // Owner
            $table->string('center_id')->unique(); // Different from id
            $table->string('center_name');
            $table->decimal('longitude', 10, 8)->nullable();
            $table->decimal('latitude', 11, 8)->nullable();
            $table->text('description')->nullable();
            $table->boolean('isManagable')->default(false); // Admin approval
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_aid');
    }
};
