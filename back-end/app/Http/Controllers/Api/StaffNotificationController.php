<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffNotificationController extends Controller
{
    private function owned(Request $request)
    {
        return DB::table('notifications')->where('notifiable_type', User::class)
            ->where('notifiable_id', $request->user()->id);
    }

    public function index(Request $request)
    {
        $request->validate(['unread' => ['sometimes', 'boolean']]);
        $query = $this->owned($request);
        if ($request->boolean('unread')) $query->whereNull('read_at');
        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(30);
        $page->through(fn ($row) => ['id' => $row->id, 'data' => json_decode($row->data, true),
            'read_at' => $row->read_at, 'created_at' => $row->created_at]);
        return response()->json(['data' => $page, 'unread_count' => $this->owned($request)->whereNull('read_at')->count()]);
    }

    public function unreadCount(Request $request)
    {
        return response()->json(['data' => ['unread_count' => $this->owned($request)->whereNull('read_at')->count()]]);
    }

    public function read(Request $request, string $id)
    {
        $query = $this->owned($request)->where('id', $id);
        abort_unless((clone $query)->exists(), 404);
        $updated = $query->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
        if ($updated) \App\Events\StaffNotificationRead::dispatch((int) $request->user()->id, $id);
        return response()->json(['success' => true]);
    }
}
