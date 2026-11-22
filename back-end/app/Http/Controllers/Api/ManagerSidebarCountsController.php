<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ManagerSidebarCountsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'online_orders' => DB::table('orders')
                ->whereIn('order_type', ['ONLINE', 'TAKEAWAY'])
                ->whereIn('status', ['PENDING', 'READY_NOTIFY_SHIPPER'])->count(),
            'purchase_orders' => DB::table('purchase_orders')->whereIn('status', PurchaseOrder::RECEIVABLE_STATUSES)->count(),
            'stock_imports' => DB::table('stock_imports')->where('status', 'DRAFT')->whereNull('purchase_order_id')->count(),
            'stocktakes' => DB::table('stocktakes')->where('status', 'OPEN')->count(),
            'stock_requests' => DB::table('stock_requests')->where('status', 'OPEN')->count(),
            'out_of_stock' => DB::table('ingredients')->where('is_active', true)->where('quantity_in_stock', '<=', 0)->count(),
            'live_chat' => DB::table('live_chat_sessions')->where('status', 'waiting')->whereNotNull('last_message_at')->count(),
        ]]);
    }
}
