<?php

// Local integration test: creates its own temporary customer and cart, then removes only those records.
if (!in_array('--run-local', $argv, true)) {
    exit("Usage: php scripts/smoke_chatbot_auth.php --run-local\n");
}
require __DIR__.'/../back-end/vendor/autoload.php';
$app = require __DIR__.'/../back-end/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ChatPendingAction;
use App\Models\ChatSession;
use App\Models\MenuItem;
use App\Models\User;
use App\Services\Chatbot\ConfirmationSummaryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$results = [];
$user = null;
$sessionId = null;
try {
    $guest = Http::acceptJson()->post('http://127.0.0.1:5173/api/chatbot/sessions', [])->throw()->json('data');
    $sessionId = $guest['id'];
    $user = User::forceCreate([
        'name' => 'Chatbot Integration Test', 'full_name' => 'Chatbot Integration Test',
        'email' => 'chatbot-smoke-'.Str::uuid().'@example.invalid', 'password' => Str::random(40),
        'is_active' => true, 'email_verified_at' => now(),
    ]);
    $token = $user->createToken('temporary-chatbot-smoke')->plainTextToken;
    $client = fn () => Http::timeout(90)->acceptJson()->withToken($token)->withHeaders(['X-Chat-Guest-Token' => $guest['guest_token']]);
    $base = 'http://127.0.0.1:5173/api';
    $chat = $client()->post("{$base}/chatbot/sessions/{$sessionId}/messages", [
        'message' => 'Xem giỏ hàng của tôi', 'use_tools' => true, 'client_message_id' => (string) Str::uuid(),
    ]);
    $results['authenticated_cart_chat'] = ['status' => $chat->status(), 'reply' => $chat->json('data.content')];
    if (!$chat->successful() || !DB::table('carts')->where('user_id', $user->id)->exists()) {
        throw new RuntimeException('Authenticated cart tool did not reach the business API.');
    }
    $menu = MenuItem::where('is_available', true)->firstOrFail();
    $requestId = (string) Str::uuid();
    $message = $client()->post("{$base}/chatbot/sessions/{$sessionId}/messages", [
        'message' => "Thêm 1 phần {$menu->name} vào giỏ, cấp cay 0, không topping, không ghi chú. Mã món {$menu->id}.",
        'use_tools' => true, 'client_message_id' => $requestId,
    ]);
    $actionId = $message->json('data.pending_actions.0.id');
    $results['prepare_cart_confirmation'] = ['status' => $message->status(), 'has_confirmation' => (bool) $actionId];
    if (!$actionId) {
        throw new RuntimeException('Chatbot did not prepare the confirmation card.');
    }
    $recovery = $client()->get("{$base}/chatbot/sessions/{$sessionId}/pending-actions");
    $results['guest_session_after_login_recovery'] = $recovery->status();
    $before = DB::table('cart_items')->whereIn('cart_id', DB::table('carts')->where('user_id', $user->id)->select('id'))->sum('quantity');
    $confirm = $client()->post("{$base}/chatbot/pending-actions/{$actionId}/confirm");
    $again = $client()->post("{$base}/chatbot/pending-actions/{$actionId}/confirm");
    $after = DB::table('cart_items')->whereIn('cart_id', DB::table('carts')->where('user_id', $user->id)->select('id'))->sum('quantity');
    $results['confirm_once'] = ['status' => $confirm->status(), 'retry_status' => $again->status(), 'quantity_before' => (int) $before, 'quantity_after' => (int) $after];
    if (!$confirm->successful() || !$again->successful() || (int) $before !== 0 || (int) $after !== 1) {
        throw new RuntimeException('Cart confirmation or retry was not idempotent.');
    }
    $cancelled = ChatPendingAction::create([
        'idempotency_key' => (string) Str::uuid(), 'session_id' => $sessionId, 'user_id' => $user->id,
        'action_type' => 'cart_add_item', 'tool_name' => 'cart_add_item', 'payload' => ['menu_item_id' => $menu->id, 'quantity' => 1],
        'endpoint' => '/api/cart', 'summary' => app(ConfirmationSummaryBuilder::class)->buildCartAddItem(['menu_item_id' => $menu->id, 'quantity' => 1]),
        'status' => 'pending', 'expires_at' => now()->addMinutes(5),
    ]);
    $results['cancel_status'] = $client()->post("{$base}/chatbot/pending-actions/{$cancelled->id}/cancel")->status();
    $results['confirm_cancelled_status'] = $client()->post("{$base}/chatbot/pending-actions/{$cancelled->id}/confirm")->status();
    if ($results['cancel_status'] !== 200 || $results['confirm_cancelled_status'] !== 409 || $recovery->status() !== 200) {
        throw new RuntimeException('Cancellation or session recovery failed.');
    }
    $results['passed'] = true;
} catch (Throwable $error) {
    $results['passed'] = false;
    $results['error'] = $error->getMessage();
} finally {
    DB::transaction(function () use ($user, $sessionId) {
        if ($sessionId) ChatSession::whereKey($sessionId)->delete();
        if ($user) {
            $user->tokens()->delete();
            DB::table('carts')->where('user_id', $user->id)->delete();
            $user->delete();
        }
    });
    $results['temporary_records_removed'] = true;
    file_put_contents(__DIR__.'/../docs/chatbot-auth-smoke-results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
}
exit($results['passed'] ? 0 : 1);
