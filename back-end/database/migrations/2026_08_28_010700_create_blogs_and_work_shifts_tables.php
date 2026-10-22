<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blogs', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('slug', 220)->unique();
            $table->text('excerpt', 500)->nullable();
            $table->longText('content');
            $table->string('cover_image', 255)->nullable();
            $table->enum('category', ['NEWS', 'PROMOTION', 'NEW_DISH', 'EVENT', 'CULINARY'])->default('NEWS');
            $table->enum('status', ['DRAFT', 'PUBLISHED', 'ARCHIVED'])->default('DRAFT');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'published_at']);
            $table->index('category');
        });

        Schema::create('work_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('work_date');
            $table->time('check_in');
            $table->time('check_out')->nullable();
            $table->decimal('total_hours', 5, 2)->nullable();
            $table->string('shift_label', 30)->nullable();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'work_date', 'shift_label']);
            $table->index(['work_date', 'shift_label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_shifts');
        Schema::dropIfExists('blogs');
    }
};