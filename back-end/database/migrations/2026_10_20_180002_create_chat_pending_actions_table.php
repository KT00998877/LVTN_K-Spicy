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
        Schema::create('chat_pending_actions', function (Blueprint $table) {
            $table->id();
            $table->char('idempotency_key', 36)->unique();
            $table->foreignId('session_id')->constrained('chat_sessions')->cascadeOnDelete();
            // chat_messages' PK in this database; chat_messages has no updated_at
            // so the FK must not rely on timestamps.
            $table->foreignId('message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action_type', 64);
            $table->string('tool_name', 64);
            $table->json('payload');
            $table->string('endpoint', 255);
            $table->json('summary');
            $table->enum('status', ['pending', 'confirmed', 'cancelled', 'expired', 'failed'])->default('pending');
            $table->json('result')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->char('request_id', 36)->nullable();
            $table->timestamps();

            $table->index(['session_id', 'status']);
            $table->index(['expires_at', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_pending_actions');
    }
};
