<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatPendingAction;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\Chatbot\PendingActionExecutor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatbotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
        // Only this connection's in-memory tables; never migrate or reset the local MySQL database.
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('email'); $table->string('password'); $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->id(); $table->string('code');
        });
        Schema::create('user_role', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id'); $table->unsignedBigInteger('role_id');
        });
        Schema::create('chat_sessions', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('user_id')->nullable(); $table->string('guest_token')->nullable();
            $table->string('status'); $table->timestamps();
        });
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('session_id'); $table->string('role');
            $table->text('content'); $table->json('metadata')->nullable(); $table->timestamp('created_at')->nullable();
        });
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug')->nullable();
            $table->string('image_url')->nullable(); $table->text('description')->nullable();
            $table->unsignedBigInteger('category_id')->nullable(); $table->boolean('is_active')->default(true);
            $table->decimal('price', 12, 2);
        });
        Schema::create('categories', function (Blueprint $table) {
            $table->id(); $table->boolean('is_active')->default(true);
        });
        (require base_path('database/migrations/2026_10_20_180002_create_chat_pending_actions_table.php'))->up();
        Http::preventStrayRequests();
    }

    private function chatSession(): ChatSession
    {
        return ChatSession::create(['status' => 'active', 'guest_token' => (string) Str::uuid()]);
    }

    private function user(): User
    {
        return User::create(['name' => 'Chat Test', 'email' => Str::uuid().'@example.test', 'password' => 'test-password']);
    }

    private function url(ChatSession $session): string
    {
        return "/api/chatbot/sessions/{$session->id}/messages";
    }

    public function test_staff_token_cannot_start_chat_session(): void
    {
        $user = $this->user();
        $roleId = DB::table('roles')->insertGetId(['code' => 'SHIPPER']);
        DB::table('user_role')->insert(['user_id' => $user->id, 'role_id' => $roleId]);
        Sanctum::actingAs($user);

        $this->withToken('staff-test-token')->postJson('/api/chatbot/sessions')->assertForbidden();
        $this->assertSame(0, ChatSession::count());
    }

    public function test_guest_ownership_validation_and_closed_session(): void
    {
        $session = $this->chatSession();
        $this->postJson($this->url($session), ['message' => 'Hello'])->assertNotFound();
        $headers = ['X-Chat-Guest-Token' => $session->guest_token];
        $this->postJson($this->url($session), ['message' => ''], $headers)->assertUnprocessable();
        $session->update(['status' => 'closed']);
        $this->postJson($this->url($session), ['message' => 'Hello'], $headers)->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_latest_history_has_current_message_once_and_retry_is_idempotent(): void
    {
        $session = $this->chatSession();
        for ($i = 0; $i < 14; $i++) {
            $session->messages()->create(['role' => $i % 2 ? 'assistant' : 'user', 'content' => "turn {$i}"]);
        }
        Http::fake(['*' => Http::response(['reply' => 'Answer', 'intent' => 'get_menu'])]);
        $payload = ['message' => 'Current question', 'client_message_id' => (string) Str::uuid(), 'use_tools' => true];
        $headers = ['X-Chat-Guest-Token' => $session->guest_token];
        $first = $this->postJson($this->url($session), $payload, $headers)->assertOk();
        $this->postJson($this->url($session), $payload, $headers)->assertOk()->assertJsonPath('data.message_id', $first->json('data.message_id'));
        $this->assertSame(16, $session->messages()->count());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => count($request['messages']) === 11
            && $request['messages'][0]['content'] === 'turn 4'
            && $request['messages'][10]['content'] === 'Current question');
        $payload['message'] = 'Changed question';
        $this->postJson($this->url($session), $payload, $headers)->assertStatus(409);
    }

    public function test_ai_failure_is_safe_and_does_not_persist_partial_history(): void
    {
        $session = $this->chatSession();
        $headers = ['X-Chat-Guest-Token' => $session->guest_token];
        Http::fake(['*' => Http::response(['detail' => ['message' => 'secret-provider-debug']], 503)]);
        $this->postJson($this->url($session), ['message' => 'Hello'], $headers)->assertStatus(503)->assertJsonPath('success', false)->assertDontSee('secret-provider-debug');
        $this->assertSame(0, $session->messages()->count());
        Http::fake(['*' => Http::failedConnection()]);
        $this->postJson($this->url($session), ['message' => 'Hello'], $headers)->assertStatus(504);
        $this->assertSame(0, $session->messages()->count());
    }

    public function test_quick_action_is_forwarded_to_the_client_and_persisted(): void
    {
        $session = $this->chatSession();
        $headers = ['X-Chat-Guest-Token' => $session->guest_token];
        Http::fake(['*' => Http::response([
            'reply' => 'Mình đặt bàn giúp bạn nhé.', 'intent' => 'make_reservation',
            'quick_action' => ['type' => 'reservation_form', 'label' => 'Đặt bàn', 'title' => 'Đặt bàn tại K-Spicy'],
        ])]);
        $response = $this->postJson($this->url($session), ['message' => '/datban'], $headers)->assertOk();
        $response->assertJsonPath('data.quick_action.type', 'reservation_form')
            ->assertJsonPath('data.quick_action.label', 'Đặt bàn');
        $this->assertSame('reservation_form', $session->messages()->latest('id')->first()->metadata['quick_action']['type']);
    }

    public function test_named_active_dishes_are_linked_and_restored_with_the_message(): void
    {
        DB::table('categories')->insert(['id' => 1, 'is_active' => true]);
        DB::table('menu_items')->insert([
            ['name' => 'Kimbap truyền thống', 'slug' => 'kimbap-truyen-thong', 'category_id' => 1, 'is_active' => true, 'price' => 30000, 'image_url' => '/storage/menu/kimbap.jpg', 'description' => 'Cơm cuộn rong biển'],
            ['name' => 'Món đã ẩn', 'slug' => 'mon-da-an', 'category_id' => 1, 'is_active' => false, 'price' => 30000, 'image_url' => null, 'description' => null],
        ]);
        $session = $this->chatSession();
        $headers = ['X-Chat-Guest-Token' => $session->guest_token];
        Http::fake(['*' => Http::response([
            'reply' => 'Bạn có thể thử Kimbap truyền thống hoặc Món đã ẩn.', 'intent' => 'get_menu',
        ])]);

        $this->postJson($this->url($session), ['message' => 'Tui ăn cay không được'], $headers)
            ->assertOk()
            ->assertJsonPath('data.menu_suggestions.0.slug', 'kimbap-truyen-thong')
            ->assertJsonPath('data.menu_suggestions.0.image_url', '/storage/menu/kimbap.jpg')
            ->assertJsonPath('data.menu_suggestions.0.description', 'Cơm cuộn rong biển')
            ->assertJsonMissing(['slug' => 'mon-da-an']);
        $assistant = $session->messages()->where('role', 'assistant')->first();
        $metadata = $assistant->metadata;
        $metadata['menu_suggestions'] = [['name' => 'Kimbap truyền thống', 'slug' => 'kimbap-truyen-thong']];
        $assistant->update(['metadata' => $metadata]);
        DB::table('menu_items')->where('slug', 'kimbap-truyen-thong')->update(['image_url' => '/storage/menu/kimbap-moi.jpg']);
        $history = $this->getJson("/api/chatbot/sessions/{$session->id}", $headers);
        $history->assertOk();
        $history
            ->assertJsonPath('data.messages.1.metadata.menu_suggestions.0.image_url', '/storage/menu/kimbap-moi.jpg')
            ->assertJsonPath('data.messages.1.metadata.menu_suggestions.0.description', 'Cơm cuộn rong biển');
    }

    public function test_unknown_quick_action_type_is_dropped(): void
    {
        $session = $this->chatSession();
        $headers = ['X-Chat-Guest-Token' => $session->guest_token];
        Http::fake(['*' => Http::response([
            'reply' => 'Answer', 'intent' => 'make_reservation',
            'quick_action' => ['type' => 'run_arbitrary_shell', 'label' => 'Boom'],
        ])]);
        $response = $this->postJson($this->url($session), ['message' => '/datban'], $headers)->assertOk();
        $this->assertNull($response->json('data.quick_action'));
        $this->assertArrayNotHasKey('quick_action', $session->messages()->latest('id')->first()->metadata);
    }

    public function test_guest_session_after_login_forwards_current_user_and_bearer(): void
    {
        $session = $this->chatSession();
        $user = $this->user();
        Sanctum::actingAs($user);
        Http::fake(['*' => Http::response(['reply' => 'Your cart is empty', 'intent' => 'view_cart'])]);
        $this->postJson($this->url($session), ['message' => 'My cart'], [
            'X-Chat-Guest-Token' => $session->guest_token, 'Authorization' => 'Bearer test-bearer',
        ])->assertOk();
        Http::assertSent(fn ($request) => $request['user_id'] === $user->id && $request['user_token'] === 'test-bearer');
    }

    private function action(User $user, array $attributes = []): ChatPendingAction
    {
        $session = $this->chatSession();
        return ChatPendingAction::create(array_merge([
            'idempotency_key' => (string) Str::uuid(), 'session_id' => $session->id, 'user_id' => $user->id,
            'action_type' => 'cart_add_item', 'tool_name' => 'cart_add_item', 'payload' => ['menu_item_id' => 1, 'quantity' => 1],
            'endpoint' => '/api/cart', 'summary' => [], 'status' => 'pending', 'expires_at' => now()->addMinutes(5),
        ], $attributes));
    }

    public function test_ai_write_proposal_creates_card_without_executing_and_recovers_after_login(): void
    {
        $session = $this->chatSession();
        Sanctum::actingAs($this->user());
        DB::table('menu_items')->insert(['id' => 1, 'name' => 'Test noodle', 'price' => 69000]);
        $this->mock(PendingActionExecutor::class)->shouldNotReceive('execute');
        Http::fake(['*' => Http::response([
            'reply' => 'Please confirm', 'intent' => 'add_to_cart', 'confirmation_required' => true,
            'tool_calls' => [['tool' => 'cart_add_item', 'error_code' => 'CONFIRMATION_REQUIRED', 'args' => ['menu_item_id' => 1, 'quantity' => 1]]],
        ])]);
        $headers = ['X-Chat-Guest-Token' => $session->guest_token];
        $response = $this->postJson($this->url($session), ['message' => 'Add one noodle'], $headers)
            ->assertOk()->assertJsonPath('data.confirmation_required', true)
            ->assertJsonPath('data.pending_actions.0.status', 'pending');
        $this->getJson("/api/chatbot/sessions/{$session->id}/pending-actions", $headers)->assertOk()
            ->assertJsonPath('data.items.0.id', $response->json('data.pending_actions.0.id'));
        $this->assertSame('pending', ChatPendingAction::first()->status);
    }

    public function test_confirmation_retry_executes_only_once(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        $action = $this->action($user);
        $this->mock(PendingActionExecutor::class)->shouldReceive('execute')->once()->andReturn(['cart_item_id' => 123]);
        $url = "/api/chatbot/pending-actions/{$action->id}/confirm";
        $this->postJson($url)->assertOk()->assertJsonPath('data.result.cart_item_id', 123);
        $this->postJson($url)->assertOk()->assertJsonPath('data.result.cart_item_id', 123);
    }

    public function test_cancel_expiry_and_ownership_prevent_execution(): void
    {
        $user = $this->user();
        Sanctum::actingAs($user);
        $this->mock(PendingActionExecutor::class)->shouldNotReceive('execute');
        $action = $this->action($user);
        $this->postJson("/api/chatbot/pending-actions/{$action->id}/cancel")->assertOk();
        $this->postJson("/api/chatbot/pending-actions/{$action->id}/confirm")->assertStatus(409);
        $expired = $this->action($user, ['expires_at' => now()->subSecond()]);
        $this->postJson("/api/chatbot/pending-actions/{$expired->id}/confirm")->assertStatus(410);
        $foreign = $this->action($this->user());
        $this->postJson("/api/chatbot/pending-actions/{$foreign->id}/confirm")->assertForbidden();
    }
}
