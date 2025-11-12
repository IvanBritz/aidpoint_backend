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
        Schema::create('beneficiary_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('beneficiary_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('recorded_by')->constrained('users')->onDelete('cascade'); // Caseworker
            
            // Attendance tracking
            $table->date('attendance_date');
            $table->enum('day_of_week', ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);
            $table->enum('status', ['present', 'absent', 'excused'])->default('present');
            $table->text('notes')->nullable(); // Optional notes for absences/excuses
            
            // Reference period for COLA calculation (month/year)
            $table->integer('month'); // 1-12
            $table->integer('year'); // e.g., 2025
            
            $table->timestamps();
            
            // Ensure one record per beneficiary per date
            $table->unique(['beneficiary_id', 'attendance_date']);
            
            // Indexes for common queries
            $table->index(['beneficiary_id', 'year', 'month']);
            $table->index(['attendance_date', 'day_of_week']);
            $table->index(['status', 'day_of_week']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('beneficiary_attendance');
    }
};