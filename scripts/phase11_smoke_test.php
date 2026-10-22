<?php
require __DIR__ . '/../back-end/vendor/autoload.php';
$app = require __DIR__ . '/../back-end/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ChatSession;
use App\Models\ChatMessage;

$s = ChatSession::create([
    'guest_token' => 'test-guest-001',
    'title' => 'Test session',
    'last_message_at' => now(),
]);
echo "Created session id={$s->id}" . PHP_EOL;

ChatMessage::create([
    'session_id' => $s->id,
    'role' => 'user',
    'content' => 'Quán có những món gì?',
    'created_at' => now(),
]);
ChatMessage::create([
    'session_id' => $s->id,
    'role' => 'assistant',
    'content' => 'Dạ có nhiều món ngon lắm ạ!',
    'metadata' => ['intent' => 'search_menu', 'tool_calls' => []],
    'created_at' => now(),
]);
echo "Inserted 2 messages" . PHP_EOL;
echo "Count: " . ChatMessage::where('session_id', $s->id)->count() . PHP_EOL;
