<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ReportService — trích xuất dữ liệu báo cáo theo filter.
 *
 * Hỗ trợ 5 loại báo cáo:
 *  - revenue: doanh thu theo ngày + phương thức + loại đơn
 *  - orders: danh sách đơn hàng (filter theo status/order_type)
 *  - top_menu: top món bán chạy
 *  - inventory: tồn kho nguyên liệu + tổng giá trị
 *  - shift: các ca làm việc (cashier shift reports) — theo cashier
 *
 * Quy tắc:
 *  - Date filter luôn theo Asia/Ho_Chi_Minh, convert về app.timezone khi query.
 *  - Money lưu ở decimal(2) trong DB; trả về dạng float cho FE.
 *  - Tất cả đều giới hạn 366 ngày để chống query nặng.
 */
class ReportService
{
    /** Loại report hỗ trợ. */
    public const TYPES = ['revenue', 'orders', 'top_menu', 'inventory', 'shift'];

    /** Loại report mà CASHIER được phép export. */
    public const CASHIER_ALLOWED = ['revenue', 'orders', 'shift'];

    /** Giới hạn khoảng báo cáo (ngày). */
    public const MAX_DAYS = 366;

    /**
     * Chuẩn hóa filter từ request, đảm bảo [from, to] hợp lệ và không quá MAX_DAYS ngày.
     *
     * @return array{from:Carbon,to:Carbon,from_date:string,to_date:string}
     */
    public function resolveRange(?string $fromDate, ?string $toDate): array
    {
        $today = now('Asia/Ho_Chi_Minh')->toDateString();
        $fromDate = $fromDate ?: now('Asia/Ho_Chi_Minh')->startOfMonth()->toDateString();
        $toDate = $toDate ?: $today;

        if ($fromDate > $toDate) {
            abort(422, 'Ngày bắt đầu phải từ ngày kết thúc trở về trước.');
        }
        if ($toDate > $today) {
            abort(422, 'Không chọn ngày trong tương lai.');
        }
        $diff = Carbon::parse($fromDate, 'Asia/Ho_Chi_Minh')->diffInDays(Carbon::parse($toDate, 'Asia/Ho_Chi_Minh'));
        if ($diff > self::MAX_DAYS) {
            abort(422, 'Khoảng báo cáo tối đa ' . self::MAX_DAYS . ' ngày.');
        }

        $tz = config('app.timezone');
        $from = Carbon::parse($fromDate, 'Asia/Ho_Chi_Minh')->setTimezone($tz)->startOfDay();
        $to = Carbon::parse($toDate, 'Asia/Ho_Chi_Minh')->setTimezone($tz)->endOfDay();

        return [
            'from' => $from, 'to' => $to,
            'from_date' => $fromDate, 'to_date' => $toDate,
        ];
    }

    /** Kiểm tra user có quyền export loại report này không. */
    public function authorizeType(string $type, array $userRoles): void
    {
        if (!in_array($type, self::TYPES, true)) {
            abort(422, 'Loại báo cáo không hợp lệ.');
        }
        $isManager = in_array('MANAGER', $userRoles, true);
        if (!$isManager && !in_array($type, self::CASHIER_ALLOWED, true)) {
            abort(403, 'Bạn không có quyền xuất báo cáo này.');
        }
    }

    /* ============================================================ */
    /* 1. Revenue                                                   */
    /* ============================================================ */

    public function buildRevenue(Carbon $from, Carbon $to): array
    {
        $payments = DB::table('payments')->where('status', 'SUCCESS')
            ->whereBetween('processed_at', [$from, $to])
            ->orderBy('processed_at')->get();

        $total = (float) $payments->sum('amount');
        $methods = collect(['CASH', 'BANK_TRANSFER', 'QR', 'ONLINE_GATEWAY', 'OTHER'])->map(fn ($m) => [
            'method' => $m,
            'amount' => round((float) $payments->where('method', $m)->sum('amount'), 2),
            'count' => $payments->where('method', $m)->count(),
        ])->values()->all();

        $invoiceOrders = DB::table('invoices as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->whereIn('i.id', $payments->pluck('invoice_id')->unique())
            ->pluck('o.order_type', 'i.id');
        $types = collect(['DINE_IN', 'TAKEAWAY', 'ONLINE'])->map(function ($type) use ($payments, $invoiceOrders) {
            $rows = $payments->filter(fn ($p) => $invoiceOrders->get($p->invoice_id) === $type);
            return [
                'order_type' => $type,
                'amount' => round((float) $rows->sum('amount'), 2),
                'count' => $rows->pluck('invoice_id')->unique()->count(),
            ];
        })->values()->all();

        $daily = [];
        $cursor = $from->copy();
        while ($cursor->lte($to)) {
            $dayKey = $cursor->toDateString();
            $rows = $payments->filter(fn ($p) => Carbon::parse($p->processed_at)->toDateString() === $dayKey);
            $daily[] = [
                'date' => $dayKey,
                'revenue' => (float) $rows->sum('amount'),
                'count' => $rows->pluck('invoice_id')->unique()->count(),
            ];
            $cursor->addDay();
        }

        return [
            'total_revenue' => $total,
            'invoice_count' => $payments->pluck('invoice_id')->unique()->count(),
            'payment_count' => $payments->count(),
            'by_method' => $methods,
            'by_order_type' => $types,
            'daily' => $daily,
        ];
    }

    /* ============================================================ */
    /* 2. Orders                                                    */
    /* ============================================================ */

    public function buildOrders(Carbon $from, Carbon $to, ?string $status = null, ?string $orderType = null): array
    {
        $q = DB::table('orders as o')
            ->leftJoin('table_sessions as ts', 'ts.id', '=', 'o.table_session_id')
            ->leftJoin('tables as t', 't.id', '=', 'ts.table_id')
            ->leftJoin('users as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('users as w', 'w.id', '=', 'o.waiter_id')
            ->whereBetween('o.placed_at', [$from, $to])
            ->orderByDesc('o.placed_at');
        if ($status) $q->where('o.status', $status);
        if ($orderType) $q->where('o.order_type', $orderType);
        $rows = $q->get([
            'o.code', 'o.status', 'o.order_type',
            'o.subtotal', 'o.discount_amount', 'o.shipping_fee', 'o.total_amount',
            'o.placed_at', 'o.completed_at',
            'o.recipient_name', 'o.recipient_phone',
            't.code as table_code', 'c.name as customer_name', 'w.name as waiter_name',
        ]);

        return [
            'count' => $rows->count(),
            'total_amount' => (float) $rows->sum('total_amount'),
            'rows' => $rows->map(fn ($r) => [
                'code' => $r->code,
                'status' => $r->status,
                'order_type' => $r->order_type,
                'subtotal' => (float) $r->subtotal,
                'discount' => (float) $r->discount_amount,
                'shipping_fee' => (float) $r->shipping_fee,
                'total_amount' => (float) $r->total_amount,
                'placed_at' => $r->placed_at,
                'completed_at' => $r->completed_at,
                'recipient_name' => $r->recipient_name,
                'recipient_phone' => $r->recipient_phone,
                'table_code' => $r->table_code,
                'customer_name' => $r->customer_name,
                'waiter_name' => $r->waiter_name,
            ])->values()->all(),
        ];
    }

    /* ============================================================ */
    /* 3. Top menu                                                  */
    /* ============================================================ */

    public function buildTopMenu(Carbon $from, Carbon $to, int $limit = 50): array
    {
        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->leftJoin('menu_items as m', 'm.id', '=', 'oi.menu_item_id')
            ->leftJoin('categories as c', 'c.id', '=', 'm.category_id')
            ->where('o.status', 'COMPLETED')
            ->whereBetween('o.completed_at', [$from, $to])
            ->where('oi.status', '!=', 'CANCELLED')
            ->groupBy('oi.menu_item_id', 'oi.item_name', 'c.name')
            ->selectRaw('oi.menu_item_id, oi.item_name, c.name as category_name, SUM(oi.quantity) as sold_qty, SUM(oi.subtotal) as revenue, COUNT(DISTINCT o.id) as order_count')
            ->orderByDesc('sold_qty')
            ->limit($limit)
            ->get();

        return [
            'rows' => $rows->map(fn ($r) => [
                'menu_item_id' => (int) $r->menu_item_id,
                'item_name' => $r->item_name,
                'category_name' => $r->category_name,
                'sold_qty' => (int) $r->sold_qty,
                'revenue' => (float) $r->revenue,
                'order_count' => (int) $r->order_count,
            ])->values()->all(),
        ];
    }

    /* ============================================================ */
    /* 4. Inventory                                                 */
    /* ============================================================ */

    public function buildInventory(): array
    {
        $rows = DB::table('ingredients')
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id', 'code', 'name', 'unit',
                'quantity_in_stock', 'min_stock_level', 'import_price',
            ]);

        $totalValue = 0;
        $lowStock = 0;
        $items = $rows->map(function ($r) use (&$totalValue, &$lowStock) {
            $stock = (float) $r->quantity_in_stock;
            $cost = (float) $r->import_price;
            $value = $stock * $cost;
            $totalValue += $value;
            $isLow = $r->min_stock_level !== null && $stock <= (float) $r->min_stock_level;
            if ($isLow) $lowStock++;
            return [
                'id' => (int) $r->id,
                'code' => $r->code,
                'name' => $r->name,
                'category_name' => null,
                'unit' => $r->unit,
                'stock' => $stock,
                'min_stock' => (float) $r->min_stock_level,
                'cost_per_unit' => $cost,
                'value' => round($value, 2),
                'is_low_stock' => $isLow,
            ];
        })->values()->all();

        return [
            'total_items' => count($items),
            'low_stock_count' => $lowStock,
            'total_value' => round($totalValue, 2),
            'rows' => $items,
        ];
    }

    /* ============================================================ */
    /* 5. Shift (cashier reports)                                   */
    /* ============================================================ */

    public function buildShift(Carbon $from, Carbon $to, ?int $cashierId = null): array
    {
        $q = DB::table('cashier_shift_reports as csr')
            ->leftJoin('users as u', 'u.id', '=', 'csr.cashier_id')
            ->where('csr.created_at', '>=', $from)
            ->where('csr.created_at', '<=', $to)
            ->orderByDesc('csr.id');
        if ($cashierId) $q->where('csr.cashier_id', $cashierId);

        $rows = $q->get(['csr.id', 'csr.cashier_id', 'csr.from_at', 'csr.to_at', 'csr.snapshot', 'u.name as cashier_name']);

        return [
            'count' => $rows->count(),
            'rows' => $rows->map(function ($r) {
                $snapshot = json_decode($r->snapshot, true) ?? [];
                return [
                    'code' => 'BC-' . str_pad((string) $r->id, 6, '0', STR_PAD_LEFT),
                    'cashier_name' => $r->cashier_name,
                    'cashier_id' => (int) $r->cashier_id,
                    'from_at' => $r->from_at,
                    'to_at' => $r->to_at,
                    'total_collected' => $snapshot['total_collected'] ?? 0,
                    'invoice_count' => $snapshot['invoice_count'] ?? 0,
                    'payment_count' => $snapshot['payment_count'] ?? 0,
                    'difference' => $snapshot['difference'] ?? 0,
                    'opening_cash' => $snapshot['opening_cash'] ?? 0,
                    'expected_cash' => $snapshot['expected_cash'] ?? 0,
                    'actual_cash' => $snapshot['actual_cash'] ?? 0,
                ];
            })->values()->all(),
        ];
    }
}