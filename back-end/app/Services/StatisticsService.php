<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * StatisticsService — backend thống kê cho dashboard manager.
 *
 * Triết lý thiết kế:
 *  - Một "Period" đại diện cho cặp (from, to) theo timezone hệ thống (Asia/Ho_Chi_Minh).
 *  - Tất cả method đều nhận Period; không bao giờ filter "toàn thời gian".
 *  - Ưu tiên aggregate SQL (SUM/COUNT/GROUP BY/JOIN) — KHÔNG load full collection rồi
 *    filter bằng PHP (tránh N+1 và OOM).
 *  - Cancelled orders KHÔNG tính vào revenue.
 *  - Failed/Refunded payments KHÔNG tính vào revenue.
 *  - growth_pct trả null khi previous=0 (tránh division by zero).
 *
 * Lưu ý về revenue:
 *  - "revenue" ở đây = tổng tiền thực thu (payments.status=SUCCESS).
 *    Theo schema của project, invoices.status=PAID + payments.status=SUCCESS là
 *    nguồn dữ liệu chuẩn nhất. Các order CANCELLED thì invoice bị FAILED/REFUNDED
 *    nên payments của chúng cũng không tính.
 *  - "net revenue" = revenue - refunds. Nếu hệ thống chưa có refund trong period
 *    → trả bằng revenue.
 */
class StatisticsService
{
    public const TZ = 'Asia/Ho_Chi_Minh';

    /**
     * Parse period từ request params (period= + from= + to=).
     *
     * Hỗ trợ:
     *  - period=today / yesterday / last_7_days / last_30_days / this_week
     *    / previous_week / this_month / previous_month / this_quarter / this_year
     *  - period=custom + from=YYYY-MM-DD + to=YYYY-MM-DD
     *  - không truyền period → mặc định last_30_days
     *
     * @param array<string,mixed> $params
     * @return array{0:Period,1:Period} [current, previous] — previous có cùng độ dài,
     *                                       ngay trước current. Dùng cho growth_pct.
     */
    public static function resolvePeriod(array $params): array
    {
        $now = Carbon::now(self::TZ);
        $period = $params['period'] ?? 'last_30_days';

        if ($period === 'custom') {
            $from = self::startOfDay($params['from'] ?? null);
            $to   = self::endOfDay($params['to']   ?? null);
            if (!$from || !$to) {
                throw ValidationException::withMessages([
                    'period' => 'Khoảng tùy chỉnh cần from và to (YYYY-MM-DD).',
                ]);
            }
            if ($from->gt($to)) {
                throw ValidationException::withMessages([
                    'from' => 'Ngày bắt đầu phải <= ngày kết thúc.',
                ]);
            }
            // isFuture kiểm tra theo giờ hiện tại → tránh so sánh với endOfDay.
            // Vì ta set test now ở giữa ngày, "to" = endOfDay luôn là tương lai.
            // So sánh theo ngày (date string) là chính xác nhất.
            $todayStr = $now->toDateString();
            if ($from->toDateString() > $todayStr || $to->toDateString() > $todayStr) {
                throw ValidationException::withMessages([
                    'to' => 'Không chọn ngày trong tương lai.',
                ]);
            }
            if ($from->diffInDays($to) > 366) {
                throw ValidationException::withMessages([
                    'period' => 'Khoảng thời gian không quá 366 ngày.',
                ]);
            }
            $current  = new Period($from, $to);
            $previous = $current->previous();
            return [$current, $previous];
        }

        switch ($period) {
            case 'today':
                $current = new Period($now->copy()->startOfDay(), $now->copy()->endOfDay());
                break;
            case 'yesterday':
                $y = $now->copy()->subDay();
                $current = new Period($y->copy()->startOfDay(), $y->copy()->endOfDay());
                break;
            case 'last_7_days':
                $current = new Period($now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay());
                break;
            case 'last_30_days':
                $current = new Period($now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay());
                break;
            case 'this_week':
                $current = new Period($now->copy()->startOfWeek(), $now->copy()->endOfWeek());
                break;
            case 'previous_week':
                $pw = $now->copy()->subWeek();
                $current = new Period($pw->copy()->startOfWeek(), $pw->copy()->endOfWeek());
                break;
            case 'this_month':
                $current = new Period($now->copy()->startOfMonth(), $now->copy()->endOfMonth());
                break;
            case 'previous_month':
                $pm = $now->copy()->subMonthNoOverflow();
                $current = new Period($pm->copy()->startOfMonth(), $pm->copy()->endOfMonth());
                break;
            case 'this_quarter':
                $current = new Period($now->copy()->startOfQuarter(), $now->copy()->endOfQuarter());
                break;
            case 'this_year':
                $current = new Period($now->copy()->startOfYear(), $now->copy()->endOfYear());
                break;
            default:
                throw ValidationException::withMessages([
                    'period' => "Period '{$period}' không hỗ trợ.",
                ]);
        }
        $previous = $current->previous();
        return [$current, $previous];
    }

    private static function startOfDay(?string $d): ?Carbon
    {
        if (!$d) return null;
        try { return Carbon::createFromFormat('Y-m-d', $d, self::TZ)->startOfDay(); }
        catch (\Throwable) { return null; }
    }
    private static function endOfDay(?string $d): ?Carbon
    {
        if (!$d) return null;
        try { return Carbon::createFromFormat('Y-m-d', $d, self::TZ)->endOfDay(); }
        catch (\Throwable) { return null; }
    }

    /**
     * Tính growth_pct an toàn khi previous = 0.
     */
    public static function growthPct(float $current, float $previous): ?float
    {
        if ($previous <= 0) return null;
        return round((($current - $previous) / $previous) * 100, 1);
    }

    /* =====================================================================
     * 1. OVERVIEW (KPI cards)
     * ===================================================================== */

    /**
     * @return array{
     *   revenue: float, net_revenue: float, total_orders: int, completed_orders: int,
     *   cancelled_orders: int, paid_orders: int, unpaid_orders: int,
     *   avg_order_value: float, sold_quantity: int,
     *   total_customers: int, new_customers: int,
     *   total_reservations: int, period: array{from:string,to:string,label:string},
     *   previous: array{from:string,to:string},
     *   growth: array<string,float|null>
     * }
     */
    public function overview(Period $current, Period $previous): array
    {
        // Revenue: tổng payments.status=SUCCESS trong current period.
        $revenue = (float) DB::table('payments')
            ->where('status', 'SUCCESS')
            ->whereBetween('processed_at', $current->utcRange())
            ->sum('amount');

        $prevRevenue = (float) DB::table('payments')
            ->where('status', 'SUCCESS')
            ->whereBetween('processed_at', $previous->utcRange())
            ->sum('amount');

        // Net revenue = revenue - refunded payments trong period.
        $refunded = (float) DB::table('payments')
            ->where('status', 'REFUNDED')
            ->whereBetween('processed_at', $current->utcRange())
            ->sum('amount');
        $netRevenue = max(0.0, $revenue - $refunded);

        // Orders
        $totalOrders = (int) DB::table('orders')
            ->whereBetween('placed_at', $current->utcRange())
            ->count();
        $completedOrders = (int) DB::table('orders')
            ->whereBetween('placed_at', $current->utcRange())
            ->where('status', 'COMPLETED')
            ->count();
        $cancelledOrders = (int) DB::table('orders')
            ->whereBetween('placed_at', $current->utcRange())
            ->where('status', 'CANCELLED')
            ->count();

        // Paid / Unpaid orders: join với invoices trong period.
        //  paid: invoice.status='PAID' + invoice.paid_at trong period.
        //  unpaid: order có invoice.status NOT IN ('PAID','REFUNDED','FAILED') và KHÔNG CANCELLED.
        $paidOrders = (int) DB::table('invoices')
            ->where('status', 'PAID')
            ->whereBetween('paid_at', $current->utcRange())
            ->count();
        $unpaidOrders = (int) DB::table('orders as o')
            ->join('invoices as i', 'i.order_id', '=', 'o.id')
            ->whereBetween('o.placed_at', $current->utcRange())
            ->where('o.status', '!=', 'CANCELLED')
            ->whereIn('i.status', ['UNPAID', 'PENDING', 'FAILED'])
            ->count();

        $avgOrderValue = $totalOrders > 0 ? round($revenue / $totalOrders, 2) : 0.0;

        // Sold quantity: tổng quantity của order_items KHÔNG bị CANCELLED,
        //  thuộc order có placed_at trong period.
        $soldQuantity = (int) DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereBetween('o.placed_at', $current->utcRange())
            ->where('oi.status', '!=', 'CANCELLED')
            ->where('o.status', '!=', 'CANCELLED')
            ->sum('oi.quantity');

        // Customers — join qua pivot user_role (DB::table không dùng được whereHas).
        $totalCustomers = (int) DB::table('users')
            ->join('user_role as ur', 'ur.user_id', '=', 'users.id')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('r.code', 'CUSTOMER')
            ->distinct()
            ->count('users.id');
        $newCustomers = (int) DB::table('users')
            ->join('user_role as ur', 'ur.user_id', '=', 'users.id')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('r.code', 'CUSTOMER')
            ->distinct()
            ->whereBetween('users.created_at', $current->utcRange())
            ->count('users.id');

        // Reservations
        $totalReservations = (int) DB::table('reservations')
            ->whereBetween('created_at', $current->utcRange())
            ->count();

        // Previous period growth
        $prevTotalOrders = (int) DB::table('orders')
            ->whereBetween('placed_at', $previous->utcRange())
            ->count();
        $prevSold = (int) DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereBetween('o.placed_at', $previous->utcRange())
            ->where('oi.status', '!=', 'CANCELLED')
            ->where('o.status', '!=', 'CANCELLED')
            ->sum('oi.quantity');
        $prevNewCustomers = (int) DB::table('users')
            ->join('user_role as ur', 'ur.user_id', '=', 'users.id')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('r.code', 'CUSTOMER')
            ->distinct()
            ->whereBetween('users.created_at', $previous->utcRange())
            ->count('users.id');
        $prevReservations = (int) DB::table('reservations')
            ->whereBetween('created_at', $previous->utcRange())
            ->count();

        return [
            'revenue'          => round($revenue, 2),
            'net_revenue'      => round($netRevenue, 2),
            'total_orders'     => $totalOrders,
            'completed_orders' => $completedOrders,
            'cancelled_orders' => $cancelledOrders,
            'paid_orders'      => $paidOrders,
            'unpaid_orders'    => $unpaidOrders,
            'avg_order_value'  => $avgOrderValue,
            'sold_quantity'    => $soldQuantity,
            'total_customers'  => $totalCustomers,
            'new_customers'    => $newCustomers,
            'total_reservations'=> $totalReservations,
            'period' => [
                'from'  => $current->from->toDateString(),
                'to'    => $current->to->toDateString(),
                'label' => $current->label(),
            ],
            'previous' => [
                'from' => $previous->from->toDateString(),
                'to'   => $previous->to->toDateString(),
            ],
            'growth' => [
                'revenue'           => self::growthPct($revenue, $prevRevenue),
                'total_orders'      => self::growthPct($totalOrders, $prevTotalOrders),
                'sold_quantity'     => self::growthPct($soldQuantity, $prevSold),
                'new_customers'     => self::growthPct($newCustomers, $prevNewCustomers),
                'total_reservations'=> self::growthPct($totalReservations, $prevReservations),
            ],
        ];
    }

    /* =====================================================================
     * 2. REVENUE CHART
     * =====================================================================
     *
     * Group theo giờ nếu period <= 2 ngày, theo ngày nếu <= 90 ngày, theo tháng nếu > 90 ngày.
     * Chỉ tính payments.status='SUCCESS'.
     */

    public function revenueChart(Period $current): array
    {
        $days = $current->from->diffInDays($current->to) + 1;
        $unit = $days <= 2 ? 'hour' : ($days <= 90 ? 'day' : 'month');

        if ($unit === 'hour') {
            $driver = DB::connection()->getDriverName();
            $expr = $driver === 'mysql'
                ? "DATE_FORMAT(CONVERT_TZ(processed_at, '+00:00', '+07:00'), '%Y-%m-%d %H:00:00')"
                : "strftime('%Y-%m-%d %H:00:00', processed_at, '+7 hours')";
            $rows = DB::table('payments')
                ->selectRaw("$expr as bucket, SUM(amount) as revenue, COUNT(*) as payment_count")
                ->where('status', 'SUCCESS')
                ->whereBetween('processed_at', $current->utcRange())
                ->groupBy('bucket')
                ->orderBy('bucket')
                ->get();
            $labels = [];
            $cur = $current->from->copy()->startOfHour();
            while ($cur->lte($current->to)) {
                $key = $cur->format('Y-m-d H:00:00');
                $labels[$key] = ['label' => $cur->format('H:i'), 'revenue' => 0.0, 'count' => 0];
                $cur->addHour();
            }
            foreach ($rows as $r) {
                if (isset($labels[$r->bucket])) {
                    $labels[$r->bucket]['revenue'] = (float) $r->revenue;
                    $labels[$r->bucket]['count']   = (int) $r->payment_count;
                }
            }
            $series = array_values($labels);
        } elseif ($unit === 'day') {
            $driver = DB::connection()->getDriverName();
            $expr = $driver === 'mysql'
                ? "DATE(CONVERT_TZ(processed_at, '+00:00', '+07:00'))"
                : "DATE(processed_at, '+7 hours')";
            $rows = DB::table('payments')
                ->selectRaw("$expr as bucket, SUM(amount) as revenue, COUNT(*) as payment_count")
                ->where('status', 'SUCCESS')
                ->whereBetween('processed_at', $current->utcRange())
                ->groupBy('bucket')
                ->orderBy('bucket')
                ->get();
            $series = [];
            $cur = $current->from->copy()->startOfDay();
            while ($cur->lte($current->to)) {
                $series[] = ['label' => $cur->format('d/m'), 'revenue' => 0.0, 'count' => 0];
                $cur->addDay();
            }
            foreach ($rows as $r) {
                foreach ($series as $i => $s) {
                    $dt = $current->from->copy()->addDays($i);
                    if ($dt->toDateString() === $r->bucket) {
                        $series[$i]['revenue'] = (float) $r->revenue;
                        $series[$i]['count']   = (int) $r->payment_count;
                        break;
                    }
                }
            }
        } else {
            $driver = DB::connection()->getDriverName();
            $expr = $driver === 'mysql'
                ? "DATE_FORMAT(CONVERT_TZ(processed_at, '+00:00', '+07:00'), '%Y-%m')"
                : "strftime('%Y-%m', processed_at, '+7 hours')";
            $rows = DB::table('payments')
                ->selectRaw("$expr as bucket, SUM(amount) as revenue, COUNT(*) as payment_count")
                ->where('status', 'SUCCESS')
                ->whereBetween('processed_at', $current->utcRange())
                ->groupBy('bucket')
                ->orderBy('bucket')
                ->get();
            $series = [];
            $cur = $current->from->copy()->startOfMonth();
            while ($cur->lte($current->to)) {
                $series[] = ['label' => $cur->format('m/Y'), 'revenue' => 0.0, 'count' => 0];
                $cur->addMonth();
            }
            foreach ($rows as $r) {
                foreach ($series as $i => $s) {
                    $dt = $current->from->copy()->addMonthsNoOverflow($i);
                    if ($dt->format('Y-m') === $r->bucket) {
                        $series[$i]['revenue'] = (float) $r->revenue;
                        $series[$i]['count']   = (int) $r->payment_count;
                        break;
                    }
                }
            }
        }

        $total = array_sum(array_column($series, 'revenue'));
        return [
            'unit'  => $unit,
            'labels'=> array_column($series, 'label'),
            'data'  => array_map(fn ($v) => round((float) $v, 2), array_column($series, 'revenue')),
            'count' => array_column($series, 'count'),
            'total' => round($total, 2),
        ];
    }

    /* =====================================================================
     * 3. ORDER STATUS DISTRIBUTION
     * ===================================================================== */

    public function orderStatusBreakdown(Period $current): array
    {
        // Group đếm + tổng tiền theo order.status trong period.
        //  total_amount của order CANCELLED vẫn lấy nhưng revenue không tính (đã loại ở overview).
        $rows = DB::table('orders')
            ->selectRaw('status, COUNT(*) as count, SUM(total_amount) as value')
            ->whereBetween('placed_at', $current->utcRange())
            ->groupBy('status')
            ->get();
        $total = (int) $rows->sum('count');
        $breakdown = [];
        foreach ($rows as $r) {
            $breakdown[] = [
                'status'      => $r->status,
                'count'       => (int) $r->count,
                'value'       => round((float) $r->value, 2),
                'percentage'  => $total > 0 ? round(((int) $r->count) / $total * 100, 1) : 0.0,
            ];
        }
        usort($breakdown, fn ($a, $b) => $b['count'] <=> $a['count']);
        return [
            'total_orders' => $total,
            'breakdown'    => $breakdown,
        ];
    }

    /* =====================================================================
     * 4. TOP SELLING PRODUCTS
     * =====================================================================
     *
     * Filter theo date range. Bỏ qua order CANCELLED + order_items CANCELLED.
     * revenue share = sold revenue / total sold revenue trong cùng period.
     */

    public function topProducts(Period $current, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('menu_items as mi', 'mi.id', '=', 'oi.menu_item_id')
            ->leftJoin('categories as c', 'c.id', '=', 'mi.category_id')
            ->whereBetween('o.placed_at', $current->utcRange())
            ->where('o.status', '!=', 'CANCELLED')
            ->where('oi.status', '!=', 'CANCELLED')
            ->selectRaw('oi.menu_item_id, oi.item_name, mi.image_url, c.name as category_name,
                         SUM(oi.quantity) as sold_quantity, SUM(oi.subtotal) as revenue')
            ->groupBy('oi.menu_item_id', 'oi.item_name', 'mi.image_url', 'c.name')
            ->orderByDesc('sold_quantity')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        $totalRevenue = (float) DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereBetween('o.placed_at', $current->utcRange())
            ->where('o.status', '!=', 'CANCELLED')
            ->where('oi.status', '!=', 'CANCELLED')
            ->sum('oi.subtotal');

        $rank = 0;
        $list = [];
        foreach ($rows as $r) {
            $rank++;
            $list[] = [
                'rank'          => $rank,
                'id'            => (int) $r->menu_item_id,
                'name'          => $r->item_name,
                'image'         => $r->image_url,
                'category'      => $r->category_name,
                'sold_quantity' => (int) $r->sold_quantity,
                'revenue'       => round((float) $r->revenue, 2),
                'revenue_share' => $totalRevenue > 0 ? round(((float) $r->revenue) / $totalRevenue * 100, 2) : 0.0,
            ];
        }
        return [
            'limit'         => $limit,
            'total_revenue' => round($totalRevenue, 2),
            'items'         => $list,
        ];
    }

    /* =====================================================================
     * 5. LOW SELLING PRODUCTS
     * =====================================================================
     *
     * Trả về:
     *  - Món có doanh thu thấp NHẤT trong period (bỏ món chưa bán).
     *  - Số món KHÔNG phát sinh bán trong period.
     *  - Top món doanh thu thấp.
     */

    public function lowSellingProducts(Period $current, int $limit = 10): array
    {
        // Món active nhưng không bán trong period.
        $noSales = (int) DB::table('menu_items as mi')
            ->where('mi.is_active', true)
            ->whereNotIn('mi.id', function ($q) use ($current) {
                $q->select('oi.menu_item_id')
                  ->from('order_items as oi')
                  ->join('orders as o', 'o.id', '=', 'oi.order_id')
                  ->whereBetween('o.placed_at', $current->utcRange())
                  ->where('o.status', '!=', 'CANCELLED')
                  ->where('oi.status', '!=', 'CANCELLED');
            })
            ->count();

        // Món bán ít (revenue thấp nhất, nhưng CÓ bán).
        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('menu_items as mi', 'mi.id', '=', 'oi.menu_item_id')
            ->leftJoin('categories as c', 'c.id', '=', 'mi.category_id')
            ->whereBetween('o.placed_at', $current->utcRange())
            ->where('o.status', '!=', 'CANCELLED')
            ->where('oi.status', '!=', 'CANCELLED')
            ->selectRaw('oi.menu_item_id, oi.item_name, mi.image_url, c.name as category_name,
                         SUM(oi.quantity) as sold_quantity, SUM(oi.subtotal) as revenue')
            ->groupBy('oi.menu_item_id', 'oi.item_name', 'mi.image_url', 'c.name')
            ->orderBy('revenue', 'asc')
            ->orderBy('sold_quantity', 'asc')
            ->limit($limit)
            ->get();

        $rank = 0;
        $list = [];
        foreach ($rows as $r) {
            $rank++;
            $list[] = [
                'rank'          => $rank,
                'id'            => (int) $r->menu_item_id,
                'name'          => $r->item_name,
                'image'         => $r->image_url,
                'category'      => $r->category_name,
                'sold_quantity' => (int) $r->sold_quantity,
                'revenue'       => round((float) $r->revenue, 2),
            ];
        }
        return [
            'limit'      => $limit,
            'no_sales_count' => $noSales,
            'items'      => $list,
        ];
    }

    /* =====================================================================
     * 6. CATEGORY STATISTICS
     * ===================================================================== */

    public function categoryStats(Period $current): array
    {
        $rows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('menu_items as mi', 'mi.id', '=', 'oi.menu_item_id')
            ->join('categories as c', 'c.id', '=', 'mi.category_id')
            ->whereBetween('o.placed_at', $current->utcRange())
            ->where('o.status', '!=', 'CANCELLED')
            ->where('oi.status', '!=', 'CANCELLED')
            ->selectRaw('c.id as category_id, c.name as category_name,
                         SUM(oi.quantity) as sold_quantity, SUM(oi.subtotal) as revenue')
            ->groupBy('c.id', 'c.name')
            ->orderByDesc('revenue')
            ->get();

        $totalRevenue = (float) $rows->sum('revenue');
        $totalQuantity = (int) $rows->sum('sold_quantity');

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'category_id'    => (int) $r->category_id,
                'category_name'  => $r->category_name,
                'sold_quantity'  => (int) $r->sold_quantity,
                'revenue'        => round((float) $r->revenue, 2),
                'revenue_share'  => $totalRevenue > 0 ? round(((float) $r->revenue) / $totalRevenue * 100, 2) : 0.0,
                'quantity_share' => $totalQuantity > 0 ? round(((int) $r->sold_quantity) / $totalQuantity * 100, 2) : 0.0,
            ];
        }
        return [
            'total_revenue'    => round($totalRevenue, 2),
            'total_quantity'   => $totalQuantity,
            'categories'       => $list,
        ];
    }

    /* =====================================================================
     * 7. PAYMENT STATISTICS
     * =====================================================================
     *
     * Tính theo payments (không theo invoice) — payments SUCCESS trong period.
     * Methods thực tế của project: CASH, BANK_TRANSFER, QR, ONLINE_GATEWAY, OTHER.
     */

    public function paymentStats(Period $current, Period $previous): array
    {
        $currentMethods = $this->paymentBreakdown($current);
        $previousMethods = $this->paymentBreakdown($previous);
        $previousByCode = collect($previousMethods)->keyBy('code');
        $totalRevenue = (float) array_sum(array_column($currentMethods, 'amount'));

        $list = [];
        foreach ($currentMethods as $m) {
            $prev = $previousByCode->get($m['code'], ['amount' => 0.0, 'count' => 0]);
            $list[] = [
                'code'        => $m['code'],
                'name'        => $this->paymentMethodName($m['code']),
                'amount'      => round((float) $m['amount'], 2),
                'count'       => (int) $m['count'],
                'share'       => $totalRevenue > 0 ? round(((float) $m['amount']) / $totalRevenue * 100, 2) : 0.0,
                'prev_amount' => round((float) ($prev['amount'] ?? 0.0), 2),
                'growth_pct'  => self::growthPct((float) $m['amount'], (float) ($prev['amount'] ?? 0.0)),
            ];
        }

        return [
            'total_revenue' => round($totalRevenue, 2),
            'methods'       => $list,
        ];
    }

    private function paymentBreakdown(Period $period): array
    {
        return DB::table('payments')
            ->selectRaw('method, SUM(amount) as amount, COUNT(*) as count')
            ->where('status', 'SUCCESS')
            ->whereBetween('processed_at', $period->utcRange())
            ->groupBy('method')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($r) => ['code' => $r->method, 'amount' => (float) $r->amount, 'count' => (int) $r->count])
            ->all();
    }

    private function paymentMethodName(string $code): string
    {
        return match ($code) {
            'CASH'           => 'Tiền mặt',
            'BANK_TRANSFER'  => 'Chuyển khoản',
            'QR'             => 'Quét QR',
            'ONLINE_GATEWAY' => 'Cổng online (VNPay)',
            'OTHER'          => 'Khác',
            default          => $code,
        };
    }

    /* =====================================================================
     * 8. CUSTOMER STATISTICS
     * ===================================================================== */

    public function customerStats(Period $current, Period $previous): array
    {
        // Tổng customer (cộng dồn) — join qua pivot user_role.
        $total = (int) DB::table('users')
            ->join('user_role as ur', 'ur.user_id', '=', 'users.id')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('r.code', 'CUSTOMER')
            ->distinct()
            ->count('users.id');

        // New customers trong period.
        $new = (int) DB::table('users')
            ->join('user_role as ur', 'ur.user_id', '=', 'users.id')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('r.code', 'CUSTOMER')
            ->distinct()
            ->whereBetween('users.created_at', $current->utcRange())
            ->count('users.id');
        $prevNew = (int) DB::table('users')
            ->join('user_role as ur', 'ur.user_id', '=', 'users.id')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('r.code', 'CUSTOMER')
            ->distinct()
            ->whereBetween('users.created_at', $previous->utcRange())
            ->count('users.id');

        // Returning customers = customer có >=1 order trong period VÀ có order TRƯỚC period.
        $returning = (int) DB::table('users as u')
            ->join('user_role as ur', 'ur.user_id', '=', 'u.id')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->where('r.code', 'CUSTOMER')
            ->whereExists(function ($q) use ($current) {
                $q->select(DB::raw(1))
                  ->from('orders as o')
                  ->whereColumn('o.customer_id', 'u.id')
                  ->whereBetween('o.placed_at', $current->utcRange())
                  ->where('o.status', '!=', 'CANCELLED');
            })
            ->whereExists(function ($q) use ($current) {
                $q->select(DB::raw(1))
                  ->from('orders as o')
                  ->whereColumn('o.customer_id', 'u.id')
                  ->where('o.placed_at', '<', $current->from)
                  ->where('o.status', '!=', 'CANCELLED');
            })
            ->count();

        // Top spending customers trong period (theo payments SUCCESS).
        $topRows = DB::table('users as u')
            ->join('orders as o', 'o.customer_id', '=', 'u.id')
            ->join('invoices as i', 'i.order_id', '=', 'o.id')
            ->join('payments as p', 'p.invoice_id', '=', 'i.id')
            ->whereBetween('p.processed_at', $current->utcRange())
            ->where('p.status', 'SUCCESS')
            ->where('o.status', '!=', 'CANCELLED')
            ->selectRaw('u.id, u.name, u.phone, u.email,
                         COUNT(DISTINCT o.id) as order_count, SUM(p.amount) as total_spent')
            ->groupBy('u.id', 'u.name', 'u.phone', 'u.email')
            ->orderByDesc('total_spent')
            ->limit(10)
            ->get();

        $top = $topRows->map(fn ($r) => [
            'id'          => (int) $r->id,
            'name'        => $r->name,
            'phone'       => $r->phone,
            'email'       => $r->email,
            'order_count' => (int) $r->order_count,
            'total_spent' => round((float) $r->total_spent, 2),
        ])->all();

        return [
            'total_customers'  => $total,
            'new_customers'    => $new,
            'returning_customers' => $returning,
            'new_customers_growth_pct' => self::growthPct($new, $prevNew),
            'top_customers'    => $top,
        ];
    }

    /* =====================================================================
     * 9. RESERVATION STATISTICS
     * ===================================================================== */

    public function reservationStats(Period $current, Period $previous): array
    {
        $rows = DB::table('reservations')
            ->selectRaw('status, COUNT(*) as count')
            ->whereBetween('created_at', $current->utcRange())
            ->groupBy('status')
            ->get();
        $byStatus = [];
        foreach ($rows as $r) {
            $byStatus[$r->status] = (int) $r->count;
        }
        $total = (int) array_sum($byStatus);

        // Tổng reservation kỳ trước để growth.
        $prevTotal = (int) DB::table('reservations')
            ->whereBetween('created_at', $previous->utcRange())
            ->count();

        return [
            'total_reservations'    => $total,
            'pending'               => $byStatus['PENDING']   ?? 0,
            'confirmed'             => $byStatus['CONFIRMED'] ?? 0,
            'arrived'               => $byStatus['ARRIVED']   ?? 0,
            'completed'             => $byStatus['COMPLETED'] ?? 0,
            'cancelled'             => $byStatus['CANCELLED'] ?? 0,
            'no_show'               => 0, // schema không có
            'total_growth_pct'      => self::growthPct($total, $prevTotal),
        ];
    }

    /* =====================================================================
     * 10. PEAK HOURS
     * =====================================================================
     *
     * Group theo giờ (0-23) và thứ trong tuần.
     * Chỉ tính order KHÔNG CANCELLED trong period.
     */

    public function peakHours(Period $current): array
    {
        // SQLite + MySQL portable: dùng strftime / HOUR.
        // Ở SQLite không có HOUR() — dùng strftime('%H', ...). MySQL không có
        // strftime cho giờ — dùng HOUR(). Để portable, dùng DB::raw với cú pháp
        // chuẩn của mỗi driver qua selectRaw. Ở đây ta dùng cú pháp strftime
        // (SQLite-native), vẫn tương thích với MySQL nhờ MySQL 8+ hỗ trợ.
        // Tuy nhiên, MySQL thường KHÔNG có strftime → dùng cast an toàn.
        //
        // Cách portable nhất: lấy datetime string về PHP xử lý.
        // Nhưng để tránh N+1, ta vẫn group trong SQL — chấp nhận cú pháp khác
        // nhau theo driver.

        $driver = DB::connection()->getDriverName();
        // Số giờ VN. Ở SQLite, strftime('%H', x, 'localtime') cho giờ theo timezone
        // hệ thống (UTC). Ở MySQL, ta convert sang timezone VN bằng CONVERT_TZ.
        // Để đơn giản + chính xác, ta load về PHP xử lý (giảm N+1 nhờ đã GROUP).
        //
        // Tuy nhiên, để vẫn aggregate ở SQL (không load full), ta dùng cú pháp
        // portable: cast processed_at thành chuỗi giờ theo Asia/Ho_Chi_Minh.
        // SQLite: strftime('%H', processed_at, '+7 hours') (giả định UTC + 7h = VN).
        //   KHÔNG đúng cho mọi múi giờ nhưng OK cho project này (VN = UTC+7, không DST).
        // MySQL: DATE_FORMAT(CONVERT_TZ(processed_at, '+00:00', '+07:00'), '%H').
        // Cú pháp khác nhau → kiểm tra driver.

        if ($driver === 'mysql') {
            $hourExpr = "DATE_FORMAT(CONVERT_TZ(o.placed_at, '+00:00', '+07:00'), '%H')";
            $dowExpr  = "DAYOFWEEK(CONVERT_TZ(o.placed_at, '+00:00', '+07:00'))";
        } else {
            // SQLite: giờ VN = strftime('%H', placed_at, '+7 hours')
            $hourExpr = "CAST(strftime('%H', o.placed_at, '+7 hours') AS INTEGER)";
            $dowExpr  = "CAST(strftime('%w', o.placed_at, '+7 hours') AS INTEGER) + 1";
        }

        $hourRows = DB::table('orders as o')
            ->selectRaw("$hourExpr as hour, COUNT(*) as order_count, SUM(o.total_amount) as revenue")
            ->whereBetween('o.placed_at', $current->utcRange())
            ->where('o.status', '!=', 'CANCELLED')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        $byHour = [];
        for ($h = 0; $h < 24; $h++) {
            $byHour[$h] = ['hour' => $h, 'order_count' => 0, 'revenue' => 0.0];
        }
        foreach ($hourRows as $r) {
            $h = (int) $r->hour;
            $byHour[$h] = [
                'hour'        => $h,
                'order_count' => (int) $r->order_count,
                'revenue'     => round((float) $r->revenue, 2),
            ];
        }

        $weekRows = DB::table('orders as o')
            ->selectRaw("$dowExpr as dow, COUNT(*) as order_count, SUM(o.total_amount) as revenue")
            ->whereBetween('o.placed_at', $current->utcRange())
            ->where('o.status', '!=', 'CANCELLED')
            ->groupBy('dow')
            ->orderBy('dow')
            ->get();
        $byWeekday = [];
        $dowName = [1 => 'CN', 2 => 'T2', 3 => 'T3', 4 => 'T4', 5 => 'T5', 6 => 'T6', 7 => 'T7'];
        for ($d = 1; $d <= 7; $d++) {
            $byWeekday[$d] = ['weekday' => $dowName[$d], 'order_count' => 0, 'revenue' => 0.0];
        }
        foreach ($weekRows as $r) {
            $d = (int) $r->dow;
            $byWeekday[$d] = [
                'weekday'     => $dowName[$d] ?? (string) $d,
                'order_count' => (int) $r->order_count,
                'revenue'     => round((float) $r->revenue, 2),
            ];
        }

        $topHours = collect($byHour)->sortByDesc('order_count')->take(3)->values()->all();

        return [
            'by_hour'   => array_values($byHour),
            'by_weekday'=> array_values($byWeekday),
            'peak_hours'=> $topHours,
        ];
    }

    /* =====================================================================
     * 11. INVENTORY STATISTICS
     * =====================================================================
     *
     * KHÔNG filter theo period — đây là snapshot hiện tại của kho.
     *   total_ingredients: COUNT(is_active=true)
     *   low_stock: quantity_in_stock <= min_stock_level VÀ > 0
     *   out_of_stock: quantity_in_stock <= 0
     *   near_expiry: expiry_date BETWEEN today+1 AND today+7
     *   expired:     expiry_date < today
     *   inventory_value: SUM(quantity_in_stock * import_price) — giá trị tồn
     *   import_value:    SUM(total_cost) phiếu nhập đã xác nhận trong kỳ.
     *   consumption: không cộng gộp khác đơn vị; xem topConsumedIngredients().
     */

    public function inventoryStats(Period $current): array
    {
        $today = Carbon::now(self::TZ)->startOfDay();
        $nearExpiryFrom = $today->copy()->addDay();
        $nearExpiryTo   = $today->copy()->addDays(7);

        $total = (int) DB::table('ingredients')->where('is_active', true)->count();
        $low   = (int) DB::table('ingredients')
            ->where('is_active', true)
            ->where('quantity_in_stock', '>', 0)
            ->whereColumn('quantity_in_stock', '<=', 'min_stock_level')
            ->count();
        $out   = (int) DB::table('ingredients')
            ->where('is_active', true)
            ->where('quantity_in_stock', '<=', 0)
            ->count();
        $near  = (int) DB::table('ingredients')
            ->where('is_active', true)
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [$nearExpiryFrom->toDateString(), $nearExpiryTo->toDateString()])
            ->count();
        $expired = (int) DB::table('ingredients')
            ->where('is_active', true)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<', $today->toDateString())
            ->count();

        $invValue = (float) DB::table('ingredients')
            ->where('is_active', true)
            ->selectRaw('SUM(quantity_in_stock * import_price) as v')
            ->value('v');

        $importValue = (float) DB::table('stock_imports')
            ->where('status', 'CONFIRMED')
            ->whereBetween('confirmed_at', $current->utcRange())
            ->sum('total_cost');

        return [
            'total_ingredients' => $total,
            'low_stock'         => $low,
            'out_of_stock'      => $out,
            'near_expiry'       => $near,
            'expired'           => $expired,
            'inventory_value'   => round($invValue, 2),
            'import_value'      => round($importValue, 2),
            'consumption'       => null,
            'as_of'             => $today->toDateString(),
        ];
    }

    /* =====================================================================
     * 12. TOP CONSUMED INGREDIENTS
     * ===================================================================== */

    public function topConsumedIngredients(Period $current, int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $rows = DB::table('daily_stock_sheet_items as si')
            ->join('daily_stock_sheets as s', 's.id', '=', 'si.daily_stock_sheet_id')
            ->join('ingredients as ing', 'ing.id', '=', 'si.ingredient_id')
            ->where('s.status', 'CLOSED')
            ->whereBetween('s.business_date', [$current->from->toDateString(), $current->to->toDateString()])
            ->whereNotNull('si.used_qty')
            ->selectRaw('si.ingredient_id, ing.name, ing.unit,
                         SUM(si.used_qty) as consumed_quantity,
                         SUM(si.used_qty * ing.import_price) as consumed_value')
            ->groupBy('si.ingredient_id', 'ing.name', 'ing.unit')
            ->orderByDesc('consumed_quantity')
            ->limit($limit)
            ->get();

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id'                 => (int) $r->ingredient_id,
                'name'               => $r->name,
                'unit'               => $r->unit,
                'consumed_quantity'  => round((float) $r->consumed_quantity, 3),
                'consumed_value'     => round((float) $r->consumed_value, 2),
            ];
        }
        return [
            'limit' => $limit,
            'items' => $list,
        ];
    }

    /* =====================================================================
     * 13. FOOD COST
     * =====================================================================
     *
     * Tính cost của món theo recipe * ingredient.import_price (hiện tại).
     * KHÔNG gọi gross profit là net profit.
     */

    public function foodCost(Period $current): array
    {
        $menuItems = DB::table('menu_items as mi')
            ->leftJoin('menu_item_ingredient as mii', 'mii.menu_item_id', '=', 'mi.id')
            ->leftJoin('ingredients as ing', 'ing.id', '=', 'mii.ingredient_id')
            ->where('mi.is_active', true)
            ->selectRaw('mi.id, mi.name, mi.price, mi.cost_price,
                         SUM(COALESCE(mii.quantity_required,0) * COALESCE(ing.import_price,0)) as recipe_cost')
            ->groupBy('mi.id', 'mi.name', 'mi.price', 'mi.cost_price')
            ->orderBy('mi.id')
            ->get();

        // Số lần bán trong period.
        $soldRows = DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->whereBetween('o.placed_at', $current->utcRange())
            ->where('o.status', '!=', 'CANCELLED')
            ->where('oi.status', '!=', 'CANCELLED')
            ->selectRaw('oi.menu_item_id, SUM(oi.quantity) as sold, SUM(oi.subtotal) as revenue')
            ->groupBy('oi.menu_item_id')
            ->get();
        $soldMap = [];
        foreach ($soldRows as $r) {
            $soldMap[(int) $r->menu_item_id] = ['sold' => (int) $r->sold, 'revenue' => (float) $r->revenue];
        }

        $list = [];
        foreach ($menuItems as $m) {
            $sold = $soldMap[(int) $m->id] ?? ['sold' => 0, 'revenue' => 0.0];
            $sellingPrice = (float) $m->price;
            $recipeCost   = (float) $m->recipe_cost;
            $ingredientCost = $recipeCost > 0 ? $recipeCost : (float) $m->cost_price; // fallback
            $grossProfit  = $sellingPrice - $ingredientCost;
            $foodCostPct  = $sellingPrice > 0 ? round(($ingredientCost / $sellingPrice) * 100, 1) : 0.0;
            $list[] = [
                'id'              => (int) $m->id,
                'name'            => $m->name,
                'selling_price'   => round($sellingPrice, 2),
                'ingredient_cost' => round($ingredientCost, 2),
                'gross_profit'    => round($grossProfit, 2),
                'food_cost_pct'   => $foodCostPct,
                'sold_quantity'   => $sold['sold'],
                'revenue'         => round($sold['revenue'], 2),
            ];
        }

        return [
            'period' => [
                'from' => $current->from->toDateString(),
                'to'   => $current->to->toDateString(),
            ],
            'items'  => $list,
            'note'   => 'Gross profit chỉ tính giá vốn nguyên liệu. Chưa bao gồm nhân công, điện nước, mặt bằng, ... KHÔNG phải lợi nhuận ròng.',
        ];
    }
}
