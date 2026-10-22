<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('status', ['PENDING', 'CONFIRMED', 'PREPARING', 'READY', 'SERVED', 'DELIVERING', 'COMPLETED', 'CANCELLED'])->default('PENDING')->change();
            $table->string('recipient_name', 100)->nullable();
            $table->string('recipient_phone', 20)->nullable();
            $table->string('delivery_address', 500)->nullable();
            $table->decimal('shipping_fee', 14, 2)->default(0);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('shipper_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handed_over_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('delivery_issue')->nullable();
            $table->index(['shipper_id', 'status']);
        });
        Schema::table('payments', fn (Blueprint $table) => $table->boolean('is_simulated')->default(false));
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 40);
            $table->string('status', 30);
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('is_simulated'));
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['shipper_id', 'status']);
            $table->dropConstrainedForeignId('shipper_id');
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn(['recipient_name', 'recipient_phone', 'delivery_address', 'shipping_fee', 'confirmed_at', 'handed_over_at', 'delivered_at', 'delivery_issue']);
        });
        // Keep DELIVERING in the enum to avoid corrupting historical delivery states.
    }
};
