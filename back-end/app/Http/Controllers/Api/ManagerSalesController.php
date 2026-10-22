<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\StaffOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ManagerSalesController extends Controller
{
    public function index(Request $request, string $kind = 'orders') {
        $invoice = $kind === 'invoices';
        $data = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'status' => ['nullable', 'string', 'max:30'],
            'order_type' => ['nullable', 'in:DINE_IN,TAKEAWAY,ONLINE'], 'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'], 'page' => ['sometimes', 'integer', 'min:1']]);
        abort_if(!empty($data['from']) && !empty($data['to']) && $data['from'] > $data['to'], 422, 'Ngày kết thúc phải từ ngày bắt đầu trở đi.');
        $q = DB::table('orders as o')->leftJoin('table_sessions as ts', 'ts.id', '=', 'o.table_session_id')
            ->leftJoin('tables as t', 't.id', '=', 'ts.table_id')->leftJoin('users as c', 'c.id', '=', 'o.customer_id')->leftJoin('users as w', 'w.id', '=', 'o.waiter_id');
        if ($invoice) $q->join('invoices as i', 'i.order_id', '=', 'o.id');
        $prefix = $invoice ? 'i' : 'o';
        if (!empty($data['status'])) {
            if ($invoice && $data['status'] === 'PARTIAL') $q->where('i.status', 'PENDING')->where('i.paid_amount', '>', 0);
            else { $q->where($prefix . '.status', $data['status']); if ($invoice && $data['status'] === 'PENDING') $q->where('i.paid_amount', 0); }
        }
        if (!empty($data['order_type'])) $q->where('o.order_type', $data['order_type']);
        $at = $invoice ? 'i.issued_at' : 'o.placed_at';
        if (!empty($data['from'])) $q->where($at, '>=', Carbon::parse($data['from'], 'Asia/Ho_Chi_Minh')->setTimezone(config('app.timezone')));
        if (!empty($data['to'])) $q->where($at, '<', Carbon::parse($data['to'], 'Asia/Ho_Chi_Minh')->addDay()->setTimezone(config('app.timezone')));
        if (!empty($data['search'])) $q->where(function ($q) use ($data, $invoice) {
            foreach (array_merge(['o.code', 't.code', 'c.name', 'w.name'], $invoice ? ['i.code'] : []) as $column) $q->orWhere($column, 'like', '%' . $data['search'] . '%');
        });
        $summary = ['count' => (clone $q)->count(), 'total' => (float) (clone $q)->sum($prefix . '.total_amount')];
        if ($invoice) $summary['paid'] = (float) (clone $q)->sum('i.paid_amount');
        $columns = [$prefix . '.id', $prefix . '.code', $prefix . '.status', $prefix . '.total_amount', $at . ' as occurred_at', 'o.id as order_id', 'o.code as order_code', 'o.order_type', 't.code as table_code', 'c.name as customer_name', 'w.name as waiter_name'];
        if ($invoice) $columns = array_merge($columns, ['i.paid_amount', 'o.invoice_exported_at']);
        $rows = $q->select($columns)->orderByDesc($at)->orderByDesc($prefix . '.id')->paginate(20);
        $rows->getCollection()->transform(function ($r) use ($invoice) {
            $r->total_amount = (float) $r->total_amount;
            $r->occurred_at = $r->occurred_at ? Carbon::parse($r->occurred_at)->toIso8601String() : null;
            if ($invoice) { $r->paid_amount = (float) $r->paid_amount; if ($r->status === 'PENDING' && $r->paid_amount > 0) $r->status = 'PARTIAL'; }
            return $r;
        });
        return response()->json(array_merge($rows->toArray(), ['summary' => $summary]));
    }
    public function invoices(Request $request) { return $this->index($request, 'invoices'); }
    public function order(int $id) {
        Order::findOrFail($id);
        $data = app(StaffOrderService::class)->getOrderDetail($id);
        $data['invoices'] = DB::table('invoices')->where('order_id', $id)->get(['id', 'code', 'status', 'total_amount', 'paid_amount']);
        return response()->json(['data' => $data]);
    }
}
