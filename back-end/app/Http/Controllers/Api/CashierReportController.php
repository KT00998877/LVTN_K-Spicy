<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierReportController extends Controller
{
    private function build(Request $request): array
    {
        $data = $request->validate([
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'from' => ['required_without:date', 'date_format:Y-m-d\TH:i'],
            'to' => ['required_without:date', 'date_format:Y-m-d\TH:i', 'after:from'],
            'opening_cash' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'cash_in' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'cash_out' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'actual_cash' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        // Input giờ ca luôn theo giờ Việt Nam; chuyển về múi giờ lưu trữ của ứng dụng.
        if (isset($data['date'])) {
            $from = Carbon::parse($data['date'], 'Asia/Ho_Chi_Minh')->startOfDay()->setTimezone(config('app.timezone'));
            if ($from->gt(now())) throw ValidationException::withMessages(['date' => ['Không thể lập báo cáo ngày trong tương lai.']]);
            $to = $from->copy()->addDay()->min(now());
        } else {
            $from = Carbon::parse($data['from'], 'Asia/Ho_Chi_Minh')->setTimezone(config('app.timezone'));
            $to = Carbon::parse($data['to'], 'Asia/Ho_Chi_Minh')->setTimezone(config('app.timezone'));
        }
        if ($to->gt(now()) || $from->diffInHours($to) > 24) {
            throw ValidationException::withMessages(['to' => ['Ca tối đa 24 giờ và không được kết thúc trong tương lai.']]);
        }
        $rows = DB::table('payments as p')->join('invoices as i', 'i.id', '=', 'p.invoice_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.processed_by')
            ->where('p.status', 'SUCCESS')->where('p.processed_at', '>=', $from)->where('p.processed_at', '<', $to)
            ->orderBy('p.processed_at')->orderBy('p.id')
            ->get(['p.id', 'p.invoice_id', 'p.method', 'p.amount', 'p.processed_by', 'p.processed_at', 'i.code as invoice_code', 'u.name as collector']);
        $cents = fn ($amount) => (int) round((float) $amount * 100);
        $methods = [];
        foreach (['CASH', 'BANK_TRANSFER', 'QR', 'ONLINE_GATEWAY', 'OTHER'] as $method) {
            $methods[$method] = $rows->where('method', $method)->sum(fn ($p) => $cents($p->amount)) / 100;
        }
        $ownCash = $rows->where('method', 'CASH')->where('processed_by', $request->user()->id)->sum(fn ($p) => $cents($p->amount));
        $expected = $cents($data['opening_cash']) + $ownCash + $cents($data['cash_in']) - $cents($data['cash_out']);
        if ($expected < 0) throw ValidationException::withMessages(['cash_out' => ['Tiền chi/nộp ra không thể lớn hơn tiền có trong két.']]);
        return [
            'report_date' => $data['date'] ?? null,
            'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
            'cashier_id' => $request->user()->id, 'cashier_name' => $request->user()->name,
            'total_collected' => $rows->sum(fn ($p) => $cents($p->amount)) / 100,
            'invoice_count' => $rows->pluck('invoice_id')->unique()->count(),
            'payment_count' => $rows->count(), 'by_method' => $methods,
            'cashier_cash' => $ownCash / 100,
            'opening_cash' => $cents($data['opening_cash']) / 100,
            'cash_in' => $cents($data['cash_in']) / 100, 'cash_out' => $cents($data['cash_out']) / 100,
            'expected_cash' => $expected / 100, 'actual_cash' => $cents($data['actual_cash']) / 100,
            'difference' => ($cents($data['actual_cash']) - $expected) / 100,
            'note' => $data['note'] ?? '',
            'payments' => $rows->map(fn ($p) => [
                'id' => $p->id, 'invoice_code' => $p->invoice_code, 'method' => $p->method,
                'amount' => $cents($p->amount) / 100, 'collector' => $p->collector,
                'processed_at' => Carbon::parse($p->processed_at)->toIso8601String(),
            ])->all(),
        ];
    }

    public function preview(Request $request) { return response()->json(['data' => $this->build($request)]); }

    public function close(Request $request)
    {
        return DB::transaction(function () use ($request) {
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $report = $this->build($request);
            $from = Carbon::parse($report['from'])->setTimezone(config('app.timezone'))->toDateTimeString();
            $to = Carbon::parse($report['to'])->setTimezone(config('app.timezone'))->toDateTimeString();
            if ($report['report_date']) $to = Carbon::parse($report['report_date'], 'Asia/Ho_Chi_Minh')->addDay()->setTimezone(config('app.timezone'))->toDateTimeString();
            $overlap = DB::table('cashier_shift_reports')->where('cashier_id', $request->user()->id)
                ->where('from_at', '<', $to)->where('to_at', '>', $from)->exists();
            if ($overlap) throw ValidationException::withMessages(['date' => ['Ngày hoặc khoảng giờ này đã có báo cáo được chốt của bạn. Vui lòng xem lịch sử.']]);
            if (($report['difference'] != 0 || $report['cash_in'] > 0 || $report['cash_out'] > 0) && !trim($report['note'])) {
                throw ValidationException::withMessages(['note' => ['Vui lòng ghi rõ lý do chênh lệch hoặc tiền nhận thêm/chi ra.']]);
            }
            $report['closed_at'] = now()->toIso8601String();
            $id = DB::table('cashier_shift_reports')->insertGetId([
                'cashier_id' => $request->user()->id, 'from_at' => $from, 'to_at' => $to,
                'snapshot' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            return response()->json(['data' => $report + ['id' => $id, 'code' => 'BC-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT)]], 201);
        });
    }

    public function index(Request $request)
    {
        $rows = DB::table('cashier_shift_reports')->where('cashier_id', $request->user()->id)->orderByDesc('id')->paginate(20);
        $rows->getCollection()->transform(function ($row) {
            $snapshot = json_decode($row->snapshot, true);
            return ['id' => $row->id, 'code' => 'BC-' . str_pad((string) $row->id, 6, '0', STR_PAD_LEFT),
                'from' => $snapshot['from'], 'to' => $snapshot['to'], 'total_collected' => $snapshot['total_collected'], 'difference' => $snapshot['difference']];
        });
        return response()->json($rows);
    }

    public function show(Request $request, int $id)
    {
        $row = DB::table('cashier_shift_reports')->where('cashier_id', $request->user()->id)->where('id', $id)->first();
        abort_unless($row, 404);
        $snapshot = json_decode($row->snapshot, true);
        $changed = false;
        if (!empty($snapshot['report_date'])) {
            $request->merge(['date' => $snapshot['report_date'], 'opening_cash' => 0,
                'cash_in' => 0, 'cash_out' => 0, 'actual_cash' => 0, 'note' => '']);
            $current = $this->build($request);
            $changed = json_encode($current['payments']) !== json_encode($snapshot['payments']);
        }
        return response()->json(['data' => $snapshot + ['has_new_data' => $changed, 'id' => $id, 'code' => 'BC-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT)]]);
    }
}
