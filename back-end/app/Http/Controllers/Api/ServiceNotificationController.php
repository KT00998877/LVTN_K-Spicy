<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceNotificationController extends Controller
{
    public function index()
    {
        $items = OrderItem::with('order.tableSession.table')->where('status', 'READY')
            ->whereHas('order', fn ($q) => $q->whereNotIn('status', ['COMPLETED', 'CANCELLED']))
            ->orderBy('ready_at')->orderBy('id')->get();
        return response()->json(['data' => $items->map(fn ($item) => [
            'id' => $item->id, 'order_id' => $item->order_id,
            'order_code' => $item->order->code, 'order_type' => $item->order->order_type,
            'table_code' => $item->order->tableSession?->table?->code,
            'name' => $item->item_name, 'quantity' => $item->quantity,
            'ready_at' => $item->ready_at?->toIso8601String(),
            'reminded_at' => $item->service_reminded_at?->toIso8601String(),
        ])]);
    }

    public function remind(int $oid, int $iid)
    {
        return DB::transaction(function () use ($oid, $iid) {
            $order = Order::lockForUpdate()->findOrFail($oid);
            $item = OrderItem::where('order_id', $oid)->lockForUpdate()->findOrFail($iid);
            if (in_array($order->status, ['COMPLETED', 'CANCELLED']) || $item->status !== 'READY') {
                throw ValidationException::withMessages(['item' => ['Món không còn chờ phục vụ.']]);
            }
            if (!$item->ready_at || $item->ready_at->gt(now()->subMinutes(5))) {
                throw ValidationException::withMessages(['item' => ['Có thể nhắc khi món chờ đủ 5 phút.']]);
            }
            if ($item->service_reminded_at?->gt(now()->subMinute())) {
                throw ValidationException::withMessages(['item' => ['Vui lòng chờ 1 phút trước khi nhắc lại.']]);
            }
            $item->service_reminded_at = now();
            $item->save();
            return response()->json(['data' => ['reminded_at' => $item->service_reminded_at->toIso8601String()]]);
        });
    }
}
