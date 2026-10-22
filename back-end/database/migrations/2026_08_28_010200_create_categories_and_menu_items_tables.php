<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->string('description', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index('is_active');
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('name', 150);
            $table->string('slug', 180)->unique();
            $table->text('description')->nullable();
            $table->string('image_url', 255)->nullable();
            $table->decimal('price', 12, 2);
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_special')->default(false);
            $table->boolean('is_new')->default(false);
            $table->unsignedInteger('preparation_minutes')->default(15);
            $table->unsignedTinyInteger('spicy_level')->default(0)->comment('0 = không cay, 1-5 = cấp độ cay');
            $table->enum('size', ['SMALL', 'LARGE'])->nullable()->comment('Size phần: SMALL = phần nhỏ, LARGE = phần lớn');
            $table->timestamps();
            $table->index(['is_available', 'is_active']);
            $table->index('category_id');
            $table->index('spicy_level');
            $table->index('size');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('categories');
    }
};