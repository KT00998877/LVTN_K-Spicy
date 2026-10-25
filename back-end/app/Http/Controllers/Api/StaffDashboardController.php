<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * StaffDashboardController — API dashboard + báo cáo cho Manager.
 *
 * Endpoints (yêu cầu auth:sanctum + role MANAGER):
 *  - GET /api/staff/dashboard/stats
 *  - GET /api/staff/dashboard/revenue?from=&to=
 *  - GET /api/staff/dashboard/menu-items
 *  - GET /api/staff/dashboard/staff-users
 */
class StaffDashboardController extends Controller
{
    /**
     * GET /api/staff/dashboard/stats
     * Trả về stats tổng quan cho dashboard (hôm nay).
     */
    private function payments($from, $to)
    {
        return DB::table('payments')->where('status', 'SUCCESS')->where('processed_at', '>=', $from)->where('processed_at', '<', $to);
    }

    public function stats(): JsonResponse
    {
        $from = now('Asia/Ho_Chi_Minh')->startOfDay()->setTimezone(config('app.timezone'));
        $to = $from->copy()->addDay();
        $today = $this->payments($from, $to);
        $revenue = (float) (clone $today)->sum('amount');
        $yesterday = (float) $this->payments($from->copy()->subDay(), $from)->sum('amount');
        $todayOrders = Order::where('placed_at', '>=', $from)->where('placed_at', '<', $to)->where('status', '!=', 'CANCELLED')->count();
        $yesterdayOrders = Order::where('placed_at', '>=', $from->copy()->subDay())->where('placed_at', '<', $from)->where('status', '!=', 'CANCELLED')->count();
        $items = OrderItem::whereHas('order', fn ($q) => $q->whereNotIn('status', ['CANCELLED', 'COMPLETED']));
        return response()->json(['data' => [
            'today_revenue' => $revenue,
            'today_orders' => $todayOrders,
            'today_invoices' => (clone $today)->distinct()->count('invoice_id'),
            'active_tables' => Table::where('status', 'OCCUPIED')->count(),
            'available_tables' => Table::where('status', 'AVAILABLE')->count(),
            'preparing_items' => (int) (clone $items)->where('status', 'PREPARING')->sum('quantity'),
            'pending_items' => (int) (clone $items)->where('status', 'PENDING')->sum('quantity'),
            'ready_items' => (int) (clone $items)->where('status', 'READY')->sum('quantity'),
            'total_staff' => User::where('is_active', true)->whereHas('roles', fn ($q) => $q->whereIn('code', ['WAITER', 'KITCHEN', 'CASHIER', 'MANAGER', 'SHIPPER']))->count(),
            'revenue_growth_pct' => $yesterday > 0 ? round(($revenue - $yesterday) / $yesterday * 100, 1) : null,
            'orders_growth_pct' => $yesterdayOrders > 0 ? round(($todayOrders - $yesterdayOrders) / $yesterdayOrders * 100, 1) : null,
        ]]);
    }

    public function revenue(Request $request): JsonResponse
    {
        $data = $request->validate(['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d']]);
        $fromDate = $data['from'] ?? now('Asia/Ho_Chi_Minh')->startOfMonth()->toDateString();
        $toDate = $data['to'] ?? now('Asia/Ho_Chi_Minh')->toDateString();
        $from = Carbon::parse($fromDate, 'Asia/Ho_Chi_Minh')->setTimezone(config('app.timezone'));
        $to = Carbon::parse($toDate, 'Asia/Ho_Chi_Minh')->addDay()->setTimezone(config('app.timezone'));
        abort_if($to <= $from || $from->diffInDays($to) > 366, 422, 'Khoảng báo cáo phải hợp lệ và không quá 366 ngày.');
        abort_if($toDate > now('Asia/Ho_Chi_Minh')->toDateString(), 422, 'Không chọn ngày trong tương lai.');
        $payments = $this->payments($from, $to)->orderBy('processed_at')->get();
        $total = (float) $payments->sum('amount');
        $days = (int) $from->diffInDays($to);
        $previous = (float) $this->payments($from->copy()->subDays($days), $from)->sum('amount');
        $methods = collect(['CASH', 'BANK_TRANSFER', 'QR', 'ONLINE_GATEWAY', 'OTHER'])->map(fn ($method) => [
            'code' => $method, 'amount' => round((float) $payments->where('method', $method)->sum('amount'), 2), 'count' => $payments->where('method', $method)->count(),
        ])->values();
        $invoiceOrders = DB::table('invoices as i')->join('orders as o', 'o.id', '=', 'i.order_id')->whereIn('i.id', $payments->pluck('invoice_id')->unique())->pluck('o.order_type', 'i.id');
        $types = collect(['DINE_IN', 'TAKEAWAY', 'ONLINE'])->map(function ($type) use ($payments, $invoiceOrders) {
            $rows = $payments->filter(fn ($p) => $invoiceOrders->get($p->invoice_id) === $type);
            return ['code' => $type, 'amount' => round((float) $rows->sum('amount'), 2), 'count' => $rows->pluck('invoice_id')->unique()->count()];
        })->values();
        $orderStatuses = Order::where('placed_at', '>=', $from)->where('placed_at', '<', $to)->selectRaw('status, COUNT(*) as count')->groupBy('status')->get();
        $orders = Order::where('placed_at', '>=', $from)->where('placed_at', '<', $to)->where('status', '!=', 'CANCELLED')->count();
        $groups = $payments->groupBy(fn ($p) => Carbon::parse($p->processed_at)->setTimezone('Asia/Ho_Chi_Minh')->toDateString());
        $daily = [];
        for ($day = Carbon::parse($fromDate); $day->toDateString() <= $toDate; $day->addDay()) {
            $rows = $groups->get($day->toDateString(), collect());
            $daily[] = ['date' => $day->toDateString(), 'revenue' => (float) $rows->sum('amount'), 'count' => $rows->pluck('invoice_id')->unique()->count()];
        }
        $top = OrderItem::selectRaw('menu_item_id, item_name, SUM(quantity) as sold_count, SUM(subtotal) as revenue')
            ->whereHas('order', fn ($q) => $q->where('completed_at', '>=', $from)->where('completed_at', '<', $to)->where('status', 'COMPLETED'))
            ->where('status', '!=', 'CANCELLED')->groupBy('menu_item_id', 'item_name')->orderByDesc('sold_count')->limit(10)->get()
            ->map(fn ($d) => ['id' => (int) $d->menu_item_id, 'name' => $d->item_name, 'sold_count' => (int) $d->sold_count, 'revenue' => (float) $d->revenue]);
        return response()->json(['data' => [
            'from_date' => $fromDate, 'to_date' => $toDate, 'total_revenue' => $total, 'total_orders' => $orders,
            'total_invoices' => $payments->pluck('invoice_id')->unique()->count(), 'avg_order_value' => $orders ? round($total / $orders, 2) : 0,
            'cash_revenue' => (float) $payments->where('method', 'CASH')->sum('amount'),
            'transfer_revenue' => (float) $payments->whereIn('method', ['BANK_TRANSFER', 'QR'])->sum('amount'),
            'daily' => $daily, 'chart_data' => collect($daily)->map(fn ($d) => ['label' => substr($d['date'], 5), 'value' => $d['revenue'], 'count' => $d['count']]),
            'top_dishes' => $top,
            'generated_at' => now()->toIso8601String(), 'payment_count' => $payments->count(),
            'previous_revenue' => $previous, 'previous_from' => $from->copy()->subDays($days)->setTimezone('Asia/Ho_Chi_Minh')->toDateString(),
            'previous_to' => $from->copy()->subDay()->setTimezone('Asia/Ho_Chi_Minh')->toDateString(),
            'growth_pct' => $previous > 0 ? round(($total - $previous) / $previous * 100, 1) : null,
            'by_method' => $methods, 'by_order_type' => $types, 'order_statuses' => $orderStatuses,
            'avg_receipt_value' => $payments->isNotEmpty() ? round($total / $payments->pluck('invoice_id')->unique()->count(), 2) : 0,
        ]]);
    }

    /**
     * GET /api/staff/dashboard/menu-items
     */
    public function menuItems(): JsonResponse
    {
        $items = MenuItem::with('category')
            ->where('is_active', true)
            ->orderBy('category_id')
            ->orderBy('name')
            ->get()
            ->map(fn (MenuItem $m) => [
                'id'           => (int) $m->id,
                'name'         => $m->name,
                'price'        => (float) $m->price,
                'category'     => $m->category?->name,
                'is_available' => $m->is_available,
                'image_url'    => $m->image_url,
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data'    => $items,
        ]);
    }

    /**
     * PATCH /api/staff/dashboard/menu-items/{id}
     */
    public function updateMenuItem(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'is_available' => ['sometimes', 'boolean'],
            'price'        => ['sometimes', 'numeric', 'min:0'],
        ]);

        /** @var MenuItem|null $item */
        $item = MenuItem::find($id);
        if (!$item) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy món.'], 404);
        }

        if (isset($data['is_available'])) $item->is_available = $data['is_available'];
        if (isset($data['price'])) $item->price = $data['price'];
        $item->save();

        return response()->json([
            'success' => true,
            'data'    => [
                'id'           => (int) $item->id,
                'name'         => $item->name,
                'price'        => (float) $item->price,
                'is_available' => $item->is_available,
            ],
        ]);
    }

    /**
     * GET /api/staff/dashboard/staff-users
     */
    public function staffUsers(): JsonResponse
    {
        $users = User::whereHas('roles', fn ($q) =>
            $q->whereIn('code', ['WAITER', 'KITCHEN', 'CASHIER', 'MANAGER', 'SHIPPER'])
        )->with('roles')->get();

        $result = $users->map(fn (User $u) => [
            'id'    => (int) $u->id,
            'name'  => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'is_active' => $u->is_active,
            'roles' => $u->roles->map(fn ($r) => $r->code)->values()->all(),
        ])->values()->all();

        return response()->json([
            'success' => true,
            'data'    => $result,
        ]);
    }

    /**
     * PATCH /api/staff/dashboard/staff-users/{id}
     */
    public function updateStaffUser(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['is_active' => ['sometimes', 'boolean'], 'name' => ['sometimes', 'string', 'max:100']]);
        $user = User::with('roles')->findOrFail($id);
        if ($user->roles->count() !== 1) {
            throw ValidationException::withMessages(['role' => 'Hãy cập nhật tài khoản nhiều vai trò tại trang Tài khoản trước.']);
        }
        $request->replace([
            'name' => $data['name'] ?? $user->name, 'email' => $user->email, 'phone' => $user->phone,
            'role' => $user->roles->first()->code, 'is_active' => $data['is_active'] ?? $user->is_active,
        ]);
        return app(ManagerAccountController::class)->save($request, $id);
    }
}
