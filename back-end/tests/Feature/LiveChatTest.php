<?php

namespace Tests\Feature;

use App\Models\LiveChatMessage;
use App\Models\LiveChatSession;
use App\Models\User;
use App\Services\LiveChatSuggestionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LiveChatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        // Service gợi ý nhanh cần URL + internal key để gọi AI Service.
        config([
            'rag.api_url' => 'http://127.0.0.1:8001',
            'rag.internal_key' => 'test-internal-key',
        ]);
        // Chỉ dựng bảng trên sqlite in-memory; không migrate/reset MySQL local.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('full_name')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->string('avatar_path')->nullable();
            $table->unsignedInteger('loyalty_points')->default(0);
            $table->boolean('can_close_daily_stock')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code');
        });
        Schema::create('user_role', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
        });
        (require base_path('database/migrations/2026_10_23_160000_create_live_chat_tables.php'))->up();

        // Middleware nhân viên kiểm tra bảng roles/user_role; seed tối thiểu.
        foreach (['MANAGER', 'WAITER', 'CASHIER', 'CUSTOMER'] as $code) {
            DB::table('roles')->insertOrIgnore(['id' => crc32($code) % 1000 + 100, 'code' => $code]);
        }

        Http::preventStrayRequests();
    }

    private function user(string $role = 'CUSTOMER'): User
    {
        $user = User::create([
            'name' => 'Test ' . $role,
            'full_name' => 'Test ' . $role,
            'email' => $role . '-' . uniqid() . '@test.local',
            'password' => 'secret',
            'is_active' => true,
        ]);
        $roleId = DB::table('roles')->where('code', $role)->value('id');
        if ($roleId) {
            DB::table('user_role')->insert(['user_id' => $user->id, 'role_id' => $roleId]);
        }
        return $user;
    }

    // ===== Khách chưa đăng nhập =====

    public function test_guest_khong_mo_duoc_phien_tu_van(): void
    {
        $response = $this->postJson('/api/live-chat/sessions');

        $response->assertStatus(401)
            ->assertJsonPath('requires_login', true);
        $this->assertSame(0, LiveChatSession::count());
    }

    public function test_khach_dang_nhap_mo_phien_thanh_cong(): void
    {
        $customer = $this->user();
        Sanctum::actingAs($customer);

        $response = $this->postJson('/api/live-chat/sessions', ['topic' => 'Hỏi về mì cay']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.subject', 'Hỏi về mì cay');
    }

    // ===== Một khách chỉ có một phiên =====

    public function test_khach_mo_lan_nua_tai_dung_phien_dang_co(): void
    {
        $customer = $this->user();
        Sanctum::actingAs($customer);

        $first = $this->postJson('/api/live-chat/sessions')->json('data.id');
        $second = $this->postJson('/api/live-chat/sessions')->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, LiveChatSession::count());
    }

    public function test_phien_da_dong_thi_tao_phien_moi(): void
    {
        $customer = $this->user();
        Sanctum::actingAs($customer);

        $first = $this->postJson('/api/live-chat/sessions')->json('data.id');
        $this->postJson("/api/live-chat/sessions/{$first}/close")->assertOk();

        $second = $this->postJson('/api/live-chat/sessions')->json('data.id');

        $this->assertNotSame($first, $second);
        $this->assertSame(2, LiveChatSession::count());
    }

    // ===== Tin nhắn =====

    public function test_khach_gui_tin_nhan_duoc(): void
    {
        $customer = $this->user();
        Sanctum::actingAs($customer);

        $sessionId = $this->postJson('/api/live-chat/sessions')->json('data.id');
        $response = $this->postJson("/api/live-chat/sessions/{$sessionId}/messages", [
            'content' => 'Cho mình hỏi giá mì cay bò',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.message.sender_type', 'customer')
            ->assertJsonPath('data.message.content', 'Cho mình hỏi giá mì cay bò');
        $this->assertSame(1, LiveChatMessage::count());
    }

    public function test_khach_khong_xem_duoc_phien_nguoi_khac(): void
    {
        $owner = $this->user();
        $other = $this->user();
        Sanctum::actingAs($owner);
        $sessionId = $this->postJson('/api/live-chat/sessions')->json('data.id');

        Sanctum::actingAs($other);
        $this->getJson("/api/live-chat/sessions/{$sessionId}")->assertNotFound();
    }

    public function test_khach_gui_tin_nhan_vao_phien_da_dong_bi_tu_choi(): void
    {
        $customer = $this->user();
        Sanctum::actingAs($customer);
        $sessionId = $this->postJson('/api/live-chat/sessions')->json('data.id');
        $this->postJson("/api/live-chat/sessions/{$sessionId}/close")->assertOk();

        $this->postJson("/api/live-chat/sessions/{$sessionId}/messages", ['content' => 'Chào'])
            ->assertStatus(409);
    }

    // ===== Nhân viên =====

    public function test_nhan_vien_xem_hang_choi(): void
    {
        $manager = $this->user('MANAGER');
        $customer = $this->user();
        Sanctum::actingAs($customer);
        $this->postJson('/api/live-chat/sessions');

        Sanctum::actingAs($manager);
        $response = $this->getJson('/api/live-chat/manager/queue');

        $response->assertOk()
            ->assertJsonPath('data.waiting.0.customer_name', $customer->full_name);
    }

    public function test_khach_khong_duoc_goi_api_quan_ly(): void
    {
        $customer = $this->user();
        Sanctum::actingAs($customer);

        $this->getJson('/api/live-chat/manager/queue')->assertStatus(403);
    }

    public function test_nhan_vien_nhan_phien(): void
    {
        $manager = $this->user('MANAGER');
        $customer = $this->user();
        Sanctum::actingAs($customer);
        $sessionId = $this->postJson('/api/live-chat/sessions')->json('data.id');

        Sanctum::actingAs($manager);
        $response = $this->postJson("/api/live-chat/manager/sessions/{$sessionId}/accept");

        $response->assertOk()->assertJsonPath('data.status', 'accepted');
        $this->assertDatabaseHas('live_chat_sessions', [
            'id' => $sessionId,
            'assigned_staff_id' => $manager->id,
        ]);
    }

    public function test_hai_nhan_vien_khong_nhan_duoc_cung_mot_phien(): void
    {
        $first = $this->user('MANAGER');
        $second = $this->user('WAITER');
        $customer = $this->user();
        Sanctum::actingAs($customer);
        $sessionId = $this->postJson('/api/live-chat/sessions')->json('data.id');

        Sanctum::actingAs($first);
        $this->postJson("/api/live-chat/manager/sessions/{$sessionId}/accept")->assertOk();

        Sanctum::actingAs($second);
        $this->postJson("/api/live-chat/manager/sessions/{$sessionId}/accept")->assertStatus(409);
    }

    public function test_nhan_vien_gui_tin_nhan(): void
    {
        $manager = $this->user('MANAGER');
        $customer = $this->user();
        Sanctum::actingAs($customer);
        $sessionId = $this->postJson('/api/live-chat/sessions')->json('data.id');

        Sanctum::actingAs($manager);
        $response = $this->postJson("/api/live-chat/manager/sessions/{$sessionId}/messages", [
            'content' => 'Chào bạn, mình kiểm tra giá cho bạn nhé.',
        ]);

        $response->assertCreated()->assertJsonPath('data.message.sender_type', 'staff');
    }

    // ===== Gợi ý nhanh =====

    public function test_goi_y_roi_dung_khi_ai_ban(): void
    {
        $manager = $this->user('MANAGER');
        $customer = $this->user();
        Sanctum::actingAs($customer);
        $sessionId = $this->postJson('/api/live-chat/sessions')->json('data.id');
        // Khách phải nói trước thì mới có lượt để AI gợi ý dựa vào.
        $this->postJson("/api/live-chat/sessions/{$sessionId}/messages", [
            'content' => 'Mì cay bò giá bao nhiêu?',
        ]);

        Sanctum::actingAs($manager);
        Http::fake([
            '*/api/v1/chat/quick-replies' => Http::response([
                'suggestions' => ['Dạ mình kiểm tra giá cho bạn nhé.', 'Món này đang có bạn ạ.'],
            ]),
        ]);

        $response = $this->getJson("/api/live-chat/manager/sessions/{$sessionId}/suggestions");

        $response->assertOk()->assertJsonPath('data.source', 'ai');
        $this->assertCount(2, $response->json('data.suggestions'));
    }

    public function test_roi_về_maubien_khi_ai_loi(): void
    {
        $manager = $this->user('MANAGER');
        $customer = $this->user();
        Sanctum::actingAs($customer);
        $sessionId = $this->postJson('/api/live-chat/sessions')->json('data.id');
        \App\Models\LiveChatQuickReply::create(['content' => 'Câu mẫu trong DB', 'is_active' => true]);

        Sanctum::actingAs($manager);
        Http::fake(['*/api/v1/chat/quick-replies' => Http::response([], 503)]);

        $response = $this->getJson("/api/live-chat/manager/sessions/{$sessionId}/suggestions");

        $response->assertOk()->assertJsonPath('data.source', 'template');
        $this->assertSame(['Câu mẫu trong DB'], $response->json('data.suggestions'));
    }

    public function test_maubien_khi_khach_chua_nhoi(): void
    {
        $manager = $this->user('MANAGER');
        $customer = $this->user();
        Sanctum::actingAs($customer);
        $sessionId = $this->postJson('/api/live-chat/sessions')->json('data.id');
        \App\Models\LiveChatQuickReply::create(['content' => 'Câu mẫu trong DB', 'is_active' => true]);

        Sanctum::actingAs($manager);
        // Không fake AI vì không được gọi tới khi khách chưa nói gì.
        $response = $this->getJson("/api/live-chat/manager/sessions/{$sessionId}/suggestions");

        $response->assertOk()->assertJsonPath('data.source', 'template');
    }

    public function test_lich_su_hop_le_tho_cho_ai(): void
    {
        $manager = $this->user('MANAGER');
        $customer = $this->user();
        Sanctum::actingAs($customer);
        $sessionId = $this->postJson('/api/live-chat/sessions')->json('data.id');
        $this->postJson("/api/live-chat/sessions/{$sessionId}/messages", ['content' => 'Mì cay giá bao nhiêu?']);

        Sanctum::actingAs($manager);
        Http::fake(function ($request) {
            $sent = json_decode($request->body(), true);
            $roles = array_column($sent['messages'] ?? [], 'role');
            $this->assertNotContains('system', $roles, 'AI chỉ nhận user/assistant');
            return Http::response(['suggestions' => ['Giá mì cay bò là 89.000đ ạ.']]);
        });

        $response = $this->getJson("/api/live-chat/manager/sessions/{$sessionId}/suggestions");

        $response->assertOk()->assertJsonPath('data.source', 'ai');
    }

    // ===== Realtime =====

    /**
     * Ký kênh realtime cần session xác thực thật (`Broadcast::auth`), không
     * dùng được Sanctum::actingAs — nên test gọi controller trực tiếp.
     */
    public function test_kenh_hang_choi_chi_nhan_vien(): void
    {
        $manager = $this->user('MANAGER');
        $customer = $this->user();

        $this->assertTrue($this->canAccess($manager, 'live-chat-queue'));
        $this->assertFalse($this->canAccess($customer, 'live-chat-queue'));
    }

    public function test_khach_chi_ky_duoc_phien_cua_hoi(): void
    {
        $customer = $this->user();
        $other = $this->user();
        Sanctum::actingAs($customer);
        $sessionId = $this->postJson('/api/live-chat/sessions')->json('data.id');

        // Kênh của chính mình → được phép.
        $this->assertTrue($this->canAccess($customer, "live-chat-session.{$sessionId}"));
        // Kênh của người khác → bị chặn.
        $this->assertFalse($this->canAccess($other, "live-chat-session.{$sessionId}"));
    }

    public function test_kenh_la_thi_bi_tu_choi(): void
    {
        $manager = $this->user('MANAGER');

        $this->assertFalse($this->canAccess($manager, 'staff.1'));
    }

    /**
     * Chạy logic ủy quyền kênh của LiveChatBroadcastAuthController.
     *
     * Gọi `canAccess()` thay vì `authenticate()`: bước ký HMAC thuộc về
     * broadcaster nên không kiểm thử được với sqlite, còn quyền truy cập kênh
     * thì có.
     */
    private function canAccess(User $user, string $channelName): bool
    {
        return app(\App\Http\Controllers\Api\LiveChatBroadcastAuthController::class)
            ->canAccess($user, $channelName);
    }
}