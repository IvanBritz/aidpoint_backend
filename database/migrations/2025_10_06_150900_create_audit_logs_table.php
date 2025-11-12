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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_type'); // 'fund_created', 'fund_updated', 'disbursement_created', 'liquidation_approved', etc.
            $table->string('event_category')->default('financial'); // 'financial', 'user_management', 'system', etc.
            $table->string('description'); // Human-readable description
            $table->json('event_data')->nullable(); // Store additional event data
            
            // User who performed the action
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable(); // Store user name for historical purposes
            $table->string('user_role')->nullable(); // Store user role at time of action
            
            // Related entities (optional, for linking to specific records)
            $table->string('entity_type')->nullable(); // 'fund_allocation', 'disbursement', 'liquidation', etc.
            $table->unsignedBigInteger('entity_id')->nullable();
            
            // Request information
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            
            // Risk level for filtering important events
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])->default('medium');
            
            $table->timestamps();
            
            // Indexes for efficient querying
            $table->index(['event_type', 'created_at']);
            $table->index(['event_category', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['risk_level', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};