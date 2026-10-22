<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\StaffOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class StaffInvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = DB::table('invoices');
        if ($status = $request->query('status')) $query->where('status', $status === 'PARTIAL' ? 'PENDING' : $status);
        if ($from = $request->query('from')) $query->whereDate('issued_at', '>=', $from);
        if ($to = $request->query('to')) $query->whereDate('issued_at', '<=', $to);
        $rows = $query->orderByDesc('issued_at')->orderByDesc('id')->get();
        $orders = Order::with(['tableSession.table', 'waiter', 'customer'])->whereIn('id', $rows->pluck('order_id'))->get()->keyBy('id');
        $payments = DB::table('payments')->whereIn('invoice_id', $rows->pluck('id'))->get()->groupBy('invoice_id');
        return response()->json(['success' => true, 'data' => $rows->map(fn($i) => $this->formatInvoice($i, $orders->get($i->order_id), $payments->get($i->id, collect())))]);
    }

    public function show(int $id)
    {
        $invoice = DB::table('invoices')->find($id);
        abort_unless($invoice, 404, 'Không tìm thấy hóa đơn.');
        $order = Order::with(['tableSession.table', 'waiter', 'customer'])->find($invoice->order_id);
        $data = $this->formatInvoice($invoice, $order, DB::table('payments')->where('invoice_id', $id)->get());
        $data['items'] = app(StaffOrderService::class)->getOrderDetail($invoice->order_id)['items'];
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function exportOrder(int $orderId)
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::lockForUpdate()->findOrFail($orderId);
            $invoice = DB::table('invoices')->where('order_id', $orderId)->where('status', 'PAID')->first();
            if (!$invoice || $order->status !== 'COMPLETED') {
                throw ValidationException::withMessages(['order' => ['Vui lòng hoàn tất thanh toán trước khi xuất hóa đơn.']]);
            }
            if (!$order->invoice_exported_at) {
                $order->invoice_exported_at = now();
                $order->save();
            }
            return $this->show($invoice->id);
        });
    }

    public function createFromOrder(int $orderId)
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::lockForUpdate()->findOrFail($orderId);
            if ($existing = DB::table('invoices')->where('order_id', $orderId)->first()) return $this->show($existing->id);
            // Thanh toán tại bàn do phục vụ xử lý; chỉ thu ngân tạo hóa đơn cho đơn mang đi.
            if ($order->order_type === 'DINE_IN' || in_array($order->status, ['COMPLETED', 'CANCELLED']) || !$order->items()->where('status', '!=', 'CANCELLED')->exists()) {
                throw ValidationException::withMessages(['order' => ['Đơn tại bàn cần được phục vụ thanh toán trước; đơn mang đi phải có món và chưa đóng.']]);
            }
            $id = DB::table('invoices')->insertGetId([
                'code' => 'HD-' . now()->format('Ymd') . '-' . str_pad((string) $orderId, 6, '0', STR_PAD_LEFT),
                'order_id' => $orderId,
                'customer_id' => $order->customer_id,
                'waiter_id' => $order->waiter_id,
                'subtotal' => $order->subtotal,
                'discount_amount' => $order->discount_amount,
                'total_amount' => $order->total_amount,
                'status' => 'UNPAID',
                'issued_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            return $this->show($id);
        });
    }

    public function pay(Request $request, int $id)
    {
        $data = $request->validate([
            'method' => ['required', 'in:CASH,TRANSFER,QR'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'transaction_ref' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        return DB::transaction(function () use ($data, $id, $request) {
            $snapshot = DB::table('invoices')->find($id);
            abort_unless($snapshot, 404);
            $order = Order::lockForUpdate()->findOrFail($snapshot->order_id);
            $invoice = DB::table('invoices')->lockForUpdate()->find($id);
            abort_if(DB::table('payments')->where('invoice_id', $id)->where('provider', 'vnpay')->where('status', 'PENDING')->exists(),
                409, 'Đang chờ kết quả VNPay. Không thể thu tiền lần nữa.');
            $remaining = round($invoice->total_amount - $invoice->paid_amount, 2);
            $amount = round($data['amount'], 2);
            if ($order->order_type === 'DINE_IN' || in_array($order->status, ['COMPLETED', 'CANCELLED']) || !in_array($invoice->status, ['UNPAID', 'PENDING']) || $amount <= 0 || $amount > $remaining) {
                throw ValidationException::withMessages(['amount' => ['Không thể thanh toán đơn này hoặc số tiền không hợp lệ.']]);
            }
            DB::table('payments')->insert([
                'invoice_id' => $id,
                'method' => $data['method'] === 'TRANSFER' ? 'BANK_TRANSFER' : $data['method'],
                'amount' => $amount,
                'status' => 'SUCCESS',
                'processed_by' => $request->user()->id,
                'transaction_ref' => $data['transaction_ref'] ?? null,
                'note' => $data['note'] ?? null,
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $paid = round($invoice->paid_amount + $amount, 2);
            $complete = $paid >= (float) $invoice->total_amount;
            DB::table('invoices')->where('id', $id)->update([
                'paid_amount' => $paid,
                'status' => $complete ? 'PAID' : 'PENDING',
                'cashier_id' => $request->user()->id,
                'paid_at' => $complete ? now() : null,
                'updated_at' => now(),
            ]);
            if ($complete) $order->update(['status' => 'COMPLETED', 'completed_at' => now()]);
            return $this->show($id);
        });
    }

    /**
     * PATCH /api/staff/invoices/{id}/status
     * Cập nhật trạng thái hóa đơn thủ công (CASHIER, MANAGER).
     * State machine: UNPAID ↔ PENDING → PAID, PAID → REFUNDED | FAILED.
     */
    public function updateStatus(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => ['required', 'in:UNPAID,PENDING,PAID,REFUNDED,FAILED'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        return DB::transaction(function () use ($data, $id, $request) {
            $invoice = DB::table('invoices')->lockForUpdate()->find($id);
            abort_unless($invoice, 404, 'Không tìm thấy hóa đơn.');

            $order = Order::lockForUpdate()->findOrFail($invoice->order_id);

            // Không cho phép thao tác thủ công trên hóa đơn đã có payment SUCCESS
            // (tránh đụng tiền thực). Cho phép qua CANCELLED order.
            abort_if(
                DB::table('payments')->where('invoice_id', $id)->where('status', 'SUCCESS')->exists() && !in_array($data['status'], ['REFUNDED', 'FAILED'], true),
                409,
                'Hóa đơn đã có thanh toán thành công. Chỉ có thể chuyển sang REFUNDED/FAILED.'
            );

            $oldStatus = $invoice->status;
            $newStatus = $data['status'];

            // State machine
            $allowed = [
                'UNPAID'    => ['UNPAID', 'PENDING', 'PAID'],
                'PENDING'   => ['UNPAID', 'PENDING', 'PAID'],
                'PAID'      => ['PAID', 'REFUNDED', 'FAILED'],
                'REFUNDED'  => ['REFUNDED'],
                'FAILED'    => ['FAILED', 'UNPAID'],
            ];
            $transitions = $allowed[$oldStatus] ?? [];
            if (!in_array($newStatus, $transitions, true)) {
                throw ValidationException::withMessages([
                    'status' => ["Không thể chuyển hóa đơn từ '{$oldStatus}' sang '{$newStatus}'."],
                ]);
            }

            $now = now();
            $update = [
                'status'     => $newStatus,
                'updated_at' => $now,
            ];

            if ($newStatus === 'PAID') {
                // Thu công: set paid = total, paid_at = now()
                $update['paid_amount'] = $invoice->total_amount;
                $update['paid_at'] = $now;
                $update['cashier_id'] = $request->user()->id;
            } elseif (in_array($newStatus, ['REFUNDED', 'FAILED'], true)) {
                // Đánh dấu refund/failed: paid_amount về 0 (giữ lịch sử payment)
                $update['paid_amount'] = 0;
                $update['paid_at'] = null;
            }

            DB::table('invoices')->where('id', $id)->update($update);

            // Audit log
            DB::table('invoice_adjustments')->insert([
                'invoice_id'  => $id,
                'adjusted_by' => $request->user()->id,
                'field_name'  => 'status',
                'old_value'   => $oldStatus,
                'new_value'   => $newStatus,
                'reason'      => $data['reason'],
                'adjusted_at' => $now,
            ]);

            // Nếu PAID thủ công → đóng order (nếu chưa đóng) + close session (DINE_IN)
            if ($newStatus === 'PAID') {
                if (!in_array($order->status, ['COMPLETED', 'CANCELLED'], true)) {
                    $order->update(['status' => 'COMPLETED', 'completed_at' => $now]);
                }
                if ($order->table_session_id) {
                    $session = \App\Models\TableSession::lockForUpdate()->find($order->table_session_id);
                    if ($session && $session->status === 'OPEN') {
                        $session->update(['status' => 'CLOSED', 'closed_at' => $now]);
                        if ($session->table) $session->table->update(['status' => 'AVAILABLE']);
                    }
                }
            }

            return $this->show($id);
        });
    }

    /**
     * PATCH /api/staff/invoices/{id}/discount
     * Quản lý nhập giảm giá thủ công cho hóa đơn (MANAGER).
     * Body:
     *   - type: 'amount' (giảm cứng) | 'percent' (giảm %)
     *   - value: số (amount > 0, percent 0-100)
     *   - reason: lý do giảm giá (bắt buộc, max 500)
     *
     * Quy tắc:
     *  - Chỉ cho phép khi hóa đơn chưa thanh toán (status UNPAID/PENDING)
     *  - Discount mới không vượt quá subtotal
     *  - Tính lại invoice.total_amount và đồng bộ sang order.discount_amount / order.total_amount
     *  - Ghi invoice_adjustments (audit log) cho cả invoice và order
     */
    public function updateDiscount(Request $request, int $id)
    {
        $data = $request->validate([
            'type'   => ['required', 'in:amount,percent'],
            'value'  => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        if ($data['type'] === 'percent' && $data['value'] > 100) {
            throw ValidationException::withMessages(['value' => ['Giảm giá phần trăm không vượt quá 100%.']]);
        }
        return DB::transaction(function () use ($data, $id, $request) {
            $invoice = DB::table('invoices')->lockForUpdate()->find($id);
            abort_unless($invoice, 404, 'Không tìm thấy hóa đơn.');
            abort_if(in_array($invoice->status, ['PAID', 'REFUNDED', 'FAILED'], true),
                409, 'Hóa đơn đã đóng, không thể thay đổi giảm giá.');

            $order = Order::lockForUpdate()->findOrFail($invoice->order_id);

            $subtotal = (float) $invoice->subtotal;
            $newDiscount = $data['type'] === 'percent'
                ? round($subtotal * ((float) $data['value'] / 100), 2)
                : round((float) $data['value'], 2);
            if ($newDiscount < 0) {
                throw ValidationException::withMessages(['value' => ['Giảm giá phải >= 0.']]);
            }
            if ($newDiscount > $subtotal) {
                throw ValidationException::withMessages(['value' => ['Giảm giá không được vượt quá tạm tính (' . number_format($subtotal) . 'đ).']]);
            }

            $oldDiscount = (float) $invoice->discount_amount;
            $newTotal = max(0, $subtotal - $newDiscount);
            $now = now();

            DB::table('invoices')->where('id', $id)->update([
                'discount_amount' => $newDiscount,
                'total_amount'    => $newTotal,
                'updated_at'      => $now,
            ]);

            // Đồng bộ sang order (giữ subtotal order không đổi — chỉ thay đổi discount + total)
            $orderSubtotal = (float) $order->subtotal;
            $orderNewDiscount = $newDiscount; // Discount của invoice và order luôn khớp
            $orderNewTotal = max(0, $orderSubtotal - $orderNewDiscount);
            $order->update([
                'discount_amount' => $orderNewDiscount,
                'total_amount'    => $orderNewTotal,
            ]);

            // Audit log: 2 bản ghi
            DB::table('invoice_adjustments')->insert([
                'invoice_id'  => $id,
                'adjusted_by' => $request->user()->id,
                'field_name'  => 'discount',
                'old_value'   => (string) $oldDiscount,
                'new_value'   => (string) $newDiscount . ' (' . $data['type'] . '=' . $data['value'] . ')',
                'reason'      => $data['reason'],
                'adjusted_at' => $now,
            ]);
            DB::table('invoice_adjustments')->insert([
                'invoice_id'  => $id,
                'adjusted_by' => $request->user()->id,
                'field_name'  => 'order_discount_synced',
                'old_value'   => (string) $order->getOriginal('discount_amount'),
                'new_value'   => (string) $orderNewDiscount,
                'reason'      => 'Đồng bộ order #' . $order->id . ' theo hóa đơn',
                'adjusted_at' => $now,
            ]);

            return $this->show($id);
        });
    }

    private function formatInvoice($i, ?Order $order, $payments): array
    {
        $adjustments = DB::table('invoice_adjustments as ia')
            ->leftJoin('users as u', 'u.id', '=', 'ia.adjusted_by')
            ->where('ia.invoice_id', $i->id)
            ->orderByDesc('ia.adjusted_at')
            ->orderByDesc('ia.id')
            ->get(['ia.id', 'ia.field_name', 'ia.old_value', 'ia.new_value', 'ia.reason', 'ia.adjusted_at', 'u.name as adjusted_by_name']);
        return [
            'id' => (int) $i->id,
            'code' => $i->code,
            'order_id' => (int) $i->order_id,
            'order_code' => $order?->code,
            'order_type' => $order?->order_type,
            'table_code' => $order?->tableSession?->table?->code,
            'customer_name' => $order?->customer?->name,
            'waiter_name' => $order?->waiter?->name,
            'subtotal' => (float) $i->subtotal,
            'discount' => (float) $i->discount_amount,
            'tax' => (float) $i->tax_amount,
            'total' => (float) $i->total_amount,
            'paid' => (float) $i->paid_amount,
            'due' => max(0, (float) $i->total_amount - (float) $i->paid_amount),
            'status' => $i->status === 'PENDING' && $i->paid_amount > 0 ? 'PARTIAL' : $i->status,
            'issued_at' => $i->issued_at ? Carbon::parse($i->issued_at)->toIso8601String() : null,
            'paid_at' => $i->paid_at ? Carbon::parse($i->paid_at)->toIso8601String() : null,
            'invoice_requested_at' => $order?->invoice_requested_at,
            'invoice_exported_at' => $order?->invoice_exported_at,
            'payments' => $payments->map(fn($p) => [
                'id' => $p->id,
                'method' => $p->method === 'BANK_TRANSFER' ? 'TRANSFER' : $p->method,
                'amount' => (float) $p->amount,
                'transaction_ref' => $p->transaction_ref,
                'note' => $p->note,
                'status' => $p->status,
                'paid_at' => $p->processed_at ? Carbon::parse($p->processed_at)->toIso8601String() : null,
            ])->values()->all(),
            'adjustments' => $adjustments->map(fn($a) => [
                'id' => (int) $a->id,
                'field' => $a->field_name,
                'old' => $a->old_value,
                'new' => $a->new_value,
                'reason' => $a->reason,
                'adjusted_by' => $a->adjusted_by_name,
                'adjusted_at' => $a->adjusted_at ? Carbon::parse($a->adjusted_at)->toIso8601String() : null,
            ])->values()->all(),
        ];
    }
}
