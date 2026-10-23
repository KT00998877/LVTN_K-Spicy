<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Contract của POST/DELETE /api/auth/me/avatar.
 *
 * Dùng sqlite in-memory + Schema::create tay, không đụng database MySQL local
 * (giống ChatbotTest).
 *
 * Ảnh giả được tạo từ byte thật của JPEG/PNG/WebP 1×1 thay vì
 * UploadedFile::fake()->image() vì hàm đó cần GD extension, không phải lúc
 * nào máy dev cũng bật.
 */
class AvatarUploadTest extends TestCase
{
    private const JPEG_1X1 = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';

    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const WEBP_1X1 = 'UklGRiIAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
        ]);

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('full_name')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->boolean('can_close_daily_stock')->default(false);
            $table->integer('loyalty_points')->default(0);
            $table->integer('total_spent')->default(0);
            $table->unsignedBigInteger('loyalty_tier_id')->nullable();
            $table->string('avatar_path')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_failed_login_at')->nullable();
            $table->unsignedInteger('failed_login_count')->default(0);
            $table->timestamps();
        });

        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->integer('min_spent')->default(0);
            $table->string('color')->nullable();
            $table->string('icon')->nullable();
            $table->text('benefits')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Storage::fake('public');
    }

    private function customer(): User
    {
        $user = User::create([
            'name' => 'Nguyễn Văn A',
            'email' => 'a@example.com',
            'password' => bcrypt('secret123'),
        ]);
        Sanctum::actingAs($user);
        return $user;
    }

    /** Ảnh 1×1 thật, không phụ thuộc GD. */
    private function imageFile(string $ext): UploadedFile
    {
        $bytes = match (strtolower($ext)) {
            'png'  => self::PNG_1X1,
            'webp' => self::WEBP_1X1,
            default => self::JPEG_1X1,
        };

        return UploadedFile::fake()->createWithContent("me.$ext", base64_decode($bytes));
    }

    public function test_upload_avatar_luu_file_va_tra_url(): void
    {
        $user = $this->customer();

        $response = $this->post('/api/auth/me/avatar', ['avatar' => $this->imageFile('jpg')]);

        $response->assertOk()->assertJsonPath('success', true);

        $user->refresh();
        $this->assertNotNull($user->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);
        $response->assertJsonPath('data.avatar_url', Storage::disk('public')->url($user->avatar_path));
    }

    public function test_upload_avatar_thay_the_bang_xoa_anh_cu(): void
    {
        $user = $this->customer();
        $this->post('/api/auth/me/avatar', ['avatar' => $this->imageFile('jpg')])->assertOk();
        $oldPath = $user->refresh()->avatar_path;

        $this->post('/api/auth/me/avatar', ['avatar' => $this->imageFile('png')])->assertOk();

        $newPath = $user->refresh()->avatar_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_upload_avatar_cho_phep_jpg_png_webp(): void
    {
        $user = $this->customer();

        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $this->post('/api/auth/me/avatar', ['avatar' => $this->imageFile($ext)])
                ->assertOk();
        }

        $this->assertNotNull($user->refresh()->avatar_path);
    }

    public function test_upload_avatar_bao_loi_khi_thieu_file(): void
    {
        $this->customer();

        $this->postJson('/api/auth/me/avatar', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar');
    }

    public function test_upload_avatar_bao_loi_khi_dinh_dang_khong_hop_le(): void
    {
        $this->customer();

        $this->post('/api/auth/me/avatar', [
            'avatar' => UploadedFile::fake()->createWithContent('virus.pdf', '%PDF-1.4 nope'),
        ])->assertStatus(422)->assertJsonValidationErrors('avatar');
    }

    public function test_upload_avatar_yeu_cau_dang_nhap(): void
    {
        $this->post('/api/auth/me/avatar', ['avatar' => $this->imageFile('jpg')])
            ->assertUnauthorized();
    }

    public function test_xoa_avatar_xoa_file_va_reset_field(): void
    {
        $user = $this->customer();
        $this->post('/api/auth/me/avatar', ['avatar' => $this->imageFile('jpg')])->assertOk();
        $path = $user->refresh()->avatar_path;

        $response = $this->deleteJson('/api/auth/me/avatar');

        $response->assertOk()->assertJsonPath('success', true);
        $response->assertJsonPath('data.avatar_url', null);
        $this->assertNull($user->refresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_avatar_url_null_khi_user_chua_upload(): void
    {
        $this->customer();

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.avatar_url', null);
    }
}