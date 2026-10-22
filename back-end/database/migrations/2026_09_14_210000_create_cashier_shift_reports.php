<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cashier_shift_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('from_at');
            $table->dateTime('to_at');
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['cashier_id', 'from_at', 'to_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('cashier_shift_reports'); }
};
