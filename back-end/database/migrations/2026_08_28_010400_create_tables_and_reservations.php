<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 80)->nullable();
            $table->unsignedSmallInteger('capacity')->default(4);
            $table->string('location', 80)->nullable();
            $table->enum('status', [
                'AVAILABLE',
                'RESERVED',
                'OCCUPIED',
                'WAITING_PAYMENT',
                'CLEANING',
            ])->default('AVAILABLE');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['status', 'is_active']);
        });

        Schema::create('table_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('table_id')->constrained('tables')->restrictOnDelete();
            $table->foreignId('waiter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('guest_count')->default(1);
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->enum('status', ['OPEN', 'CANCELLED', 'CLOSED'])->default('OPEN');
            $table->timestamps();
            $table->index(['table_id', 'status']);
            $table->index(['status', 'opened_at']);
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('customer_name', 120);
            $table->string('customer_phone', 20);
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('tables')->nullOnDelete();
            $table->unsignedSmallInteger('guest_count');
            $table->date('reserved_date');
            $table->time('reserved_time');
            $table->text('note')->nullable();
            $table->enum('status', [
                'PENDING',
                'CONFIRMED',
                'ARRIVED',
                'CANCELLED',
                'COMPLETED',
            ])->default('PENDING');
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['reserved_date', 'reserved_time']);
            $table->index(['status', 'reserved_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('table_sessions');
        Schema::dropIfExists('tables');
    }
};