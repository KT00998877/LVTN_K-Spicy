<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // BR-DELIVERY-RATING-01: khách chấm điểm dịch vụ giao cho đơn ONLINE đã hoàn thành.
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('delivery_rating')->nullable()->after('delivery_issue');
            $table->text('delivery_comment')->nullable()->after('delivery_rating');
            $table->timestamp('delivery_rated_at')->nullable()->after('delivery_comment');
        });

        // BR-DELIVERY-RATING-02: ảnh minh hoạ kèm đánh giá (tối đa 5 ảnh / đơn).
        Schema::create('order_delivery_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('url', 255);
            $table->string('mime', 40)->nullable();
            $table->unsignedInteger('size_kb')->default(0);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['order_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_delivery_photos');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['delivery_rating', 'delivery_comment', 'delivery_rated_at']);
        });
    }
};
