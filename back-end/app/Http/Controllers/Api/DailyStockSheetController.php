<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use App\Models\InventoryTransaction;
use App\Services\InventoryService;
use App\Services\ExpectedUsageService;
use App\Services\MenuAvailabilityService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DailyStockSheetController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = DB::table('daily_stock_sheets as s')
            ->leftJoin('users as u', 'u.id', '=', 's.closed_by')
            ->select('s.business_date', 's.status', 's.closed_at', 'u.name as closed_by_name')
            ->orderByDesc('s.business_date')->limit(30)->get();

        return response()->json(['data' => $rows]);
    }

    public function show(string $date): JsonResponse
    {
        $this->validDate($date);
        $sheet = DB::table('daily_stock_sheets')->where('business_date', $date)->first();
        abort_if(!$sheet && $date !== Carbon::now('Asia/Ho_Chi_Minh')->toDateString(), 404, 'Chưa có sổ cho ngày này.');
        $previous = DB::table('daily_stock_sheets')
            ->where('business_date', '<', $date)->where('status', 'CLOSED')
            ->orderByDesc('business_date')->first();
        $ingredients = Ingredient::with('category')->where('is_active', true)
            ->orderBy('ingredient_category_id')->orderBy('name')->get();
        $saved = $sheet ? DB::table('daily_stock_sheet_items')
            ->where('daily_stock_sheet_id', $sheet->id)->get()->keyBy('ingredient_id') : collect();
        $opening = $previous ? DB::table('daily_stock_sheet_items')
            ->where('daily_stock_sheet_id', $previous->id)->pluck('counted_qty', 'ingredient_id') : collect();
        $changes = $this->changes($previous?->closed_at, $sheet?->status === 'CLOSED' ? Carbon::parse($sheet->counted_at) : now());
        $expected = app(ExpectedUsageService::class)->between($previous?->closed_at, $sheet?->status === 'CLOSED' ? Carbon::parse($sheet->counted_at) : now());
        $comparisonComplete = !$previous || ($expected['missing_recipe_items'] === 0 && $expected['legacy_items'] === 0);
        $items = $sheet?->status === 'CLOSED'
            ? DB::table('daily_stock_sheet_items as si')->join('ingredients as i', 'i.id', '=', 'si.ingredient_id')
                ->where('si.daily_stock_sheet_id', $sheet->id)
                ->leftJoin('ingredient_categories as ic', 'ic.id', '=', 'i.ingredient_category_id')
                ->select('si.*', 'i.code', 'i.name', 'i.unit', 'ic.name as category_name')
                ->orderBy('ic.sort_order')->orderBy('i.name')->get()
                ->map(fn ($row) => $this->withExpected($this->presentItem($row) + ['sources' => $changes[$row->ingredient_id]['sources'] ?? []], $expected, $comparisonComplete, (bool) $previous))->all()
            : $ingredients->map(function ($ingredient) use ($saved, $opening, $changes, $previous, $expected, $comparisonComplete) {
                $id = $ingredient->id;
                $counted = $saved->get($id)?->counted_qty;
                $start = $previous ? (float) ($opening->get($id) ?? 0) : null;
                $movement = $changes[$id] ?? ['incoming' => 0, 'outgoing' => 0, 'correction' => 0];
                return $this->withExpected([
                    'ingredient_id' => $id, 'code' => $ingredient->code, 'name' => $ingredient->name,
                    'unit' => $ingredient->unit, 'category_name' => $ingredient->category?->name,
                    'opening_qty' => $start,
                    'incoming_qty' => $movement['incoming'], 'outgoing_qty' => $movement['outgoing'],
                    'correction_qty' => $movement['correction'],
                    'sources' => $movement['sources'] ?? [],
                    'explanation' => $saved->get($id)?->explanation,
                    'counted_qty' => $counted === null ? null : (float) $counted,
                    'used_qty' => $previous && $counted !== null
                        ? round($start + $movement['incoming'] - $movement['outgoing'] + $movement['correction'] - (float) $counted, 2)
                        : null,
                ], $expected, $comparisonComplete, (bool) $previous);
            })->all();

        $reserved = $date === Carbon::now('Asia/Ho_Chi_Minh')->toDateString() && $sheet?->status !== 'CLOSED'
            ? app(MenuAvailabilityService::class)->pendingUsage() : null;
        $items = array_map(function (array $item) use ($reserved) {
            $item['reserved_qty'] = $reserved === null ? null : round($reserved[$item['ingredient_id']] ?? 0, 3);
            $item['available_estimate_qty'] = $item['expected_balance_qty'] !== null && $item['reserved_qty'] !== null
                ? round($item['expected_balance_qty'] - $item['reserved_qty'], 3) : null;
            return $item;
        }, $items);

        return response()->json(['data' => [
            'business_date' => $date, 'status' => $sheet?->status ?? 'NEW',
            'baseline' => $previous === null, 'previous_date' => $previous?->business_date,
            'movement_version' => $sheet?->status === 'CLOSED' ? (int) $sheet->movement_version : (int) InventoryTransaction::max('id'),
            'counted_at' => $sheet?->counted_at, 'closed_at' => $sheet?->closed_at, 'closed_by' => $sheet?->closed_by,
            'missing_recipe_items' => $expected['missing_recipe_items'], 'legacy_items' => $expected['legacy_items'],
            'items' => $items,
        ]]);
    }

    public function save(Request $request, string $date): JsonResponse
    {
        $this->validDate($date, true);
        $data = $request->validate([
            'items' => ['required', 'array'],
            'movement_version' => ['required', 'integer', 'min:0'],
            'items.*.ingredient_id' => ['required', 'integer', 'distinct', 'exists:ingredients,id'],
            'items.*.counted_qty' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'items.*.explanation' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($date, $data, $request) {
            $sheet = DB::table('daily_stock_sheets')->where('business_date', $date)->lockForUpdate()->first();
            if ($sheet?->status === 'CLOSED') {
                throw ValidationException::withMessages(['date' => 'Sổ ngày này đã chốt.']);
            }
            $latestMovement = (int) InventoryTransaction::max('id');
            if ($latestMovement !== (int) $data['movement_version']) {
                throw ValidationException::withMessages(['movement_version' => 'Kho đã phát sinh giao dịch mới. Hãy tải lại sổ trước khi lưu số đếm.']);
            }
            $activeIds = Ingredient::where('is_active', true)->orderBy('id')->pluck('id')->all();
            $submittedIds = array_map('intval', array_column($data['items'], 'ingredient_id'));
            sort($submittedIds);
            if ($submittedIds !== $activeIds) {
                throw ValidationException::withMessages(['items' => 'Danh sách nguyên liệu đã thay đổi. Hãy tải lại sổ và đếm đủ tất cả nguyên liệu.']);
            }
            if (!$sheet) {
                $id = DB::table('daily_stock_sheets')->insertGetId([
                    'business_date' => $date, 'status' => 'DRAFT', 'created_by' => $request->user()->id,
                    'movement_version' => $latestMovement,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                $id = $sheet->id;
                DB::table('daily_stock_sheets')->where('id', $id)->update([
                    'movement_version' => $latestMovement, 'updated_at' => now(),
                ]);
            }
            DB::table('daily_stock_sheet_items')->where('daily_stock_sheet_id', $id)
                ->whereNotIn('ingredient_id', $activeIds)->delete();
            foreach ($data['items'] as $row) {
                $explanation = trim($row['explanation'] ?? '');
                DB::table('daily_stock_sheet_items')->updateOrInsert(
                    ['daily_stock_sheet_id' => $id, 'ingredient_id' => $row['ingredient_id']],
                    ['counted_qty' => $row['counted_qty'] ?? null,
                        'explanation' => $explanation === '' ? null : $explanation, 'updated_at' => now()]
                );
            }
        });

        return $this->show($date);
    }

    public function close(string $date, InventoryService $inventory, Request $request): JsonResponse
    {
        $this->validDate($date, true);
        abort_unless($request->user()->can_close_daily_stock, 403, 'Chỉ bếp trưởng được chốt sổ kho.');
        $data = $request->validate(['counted_at' => ['required', 'date_format:Y-m-d H:i:s']]);
        $countedAt = Carbon::createFromFormat('Y-m-d H:i:s', $data['counted_at'], 'Asia/Ho_Chi_Minh');
        if ($countedAt->toDateString() !== $date || $countedAt->isFuture()) {
            throw ValidationException::withMessages(['counted_at' => 'Giờ đếm phải thuộc ngày hôm nay và không ở tương lai.']);
        }
        DB::transaction(function () use ($date, $inventory, $request, $countedAt) {
            $sheet = DB::table('daily_stock_sheets')->where('business_date', $date)->lockForUpdate()->first();
            if (!$sheet) {
                throw ValidationException::withMessages(['date' => 'Hãy lưu số đếm trước khi chốt sổ.']);
            }
            if ($sheet->status === 'CLOSED') return;
            $previous = DB::table('daily_stock_sheets')->where('business_date', '<', $date)
                ->where('status', 'CLOSED')->orderByDesc('business_date')->first();
            if ($previous && $countedAt->lessThanOrEqualTo(Carbon::parse($previous->closed_at))) {
                throw ValidationException::withMessages(['counted_at' => 'Giờ đếm phải sau lần chốt trước.']);
            }
            $ingredients = Ingredient::where('is_active', true)->orderBy('id')->lockForUpdate()->get();
            if ((int) InventoryTransaction::max('id') !== (int) $sheet->movement_version) {
                throw ValidationException::withMessages(['movement_version' => 'Kho đã phát sinh giao dịch sau lần lưu sổ. Hãy tải lại, kiểm tra và lưu số đếm trước khi chốt.']);
            }
            if (InventoryTransaction::where('transacted_at', '>', $countedAt)
                ->where(function ($query) { $query->whereNull('reference_type')->orWhere('reference_type', '!=', 'daily_stock_sheet'); })
                ->exists()) {
                throw ValidationException::withMessages(['counted_at' => 'Đã có giao dịch kho sau giờ đếm. Hãy đếm lại rồi cập nhật giờ đếm.']);
            }
            $items = DB::table('daily_stock_sheet_items')->where('daily_stock_sheet_id', $sheet->id)
                ->lockForUpdate()->get()->keyBy('ingredient_id');
            if ($ingredients->isEmpty() || $items->count() !== $ingredients->count()) {
                throw ValidationException::withMessages(['items' => 'Sổ phải có đủ tất cả nguyên liệu đang sử dụng.']);
            }
            $opening = $previous ? DB::table('daily_stock_sheet_items')
                ->where('daily_stock_sheet_id', $previous->id)->pluck('counted_qty', 'ingredient_id') : collect();
            $closedAt = now();
            $changes = $this->changes($previous?->closed_at, $countedAt);
            foreach ($ingredients as $ingredient) {
                $row = $items->get($ingredient->id);
                if (!$row || $row->counted_qty === null) {
                    throw ValidationException::withMessages(['items' => "Chưa đếm nguyên liệu {$ingredient->name}."]);
                }
                $counted = (float) $row->counted_qty;
                $start = $previous ? (float) ($opening->get($ingredient->id) ?? 0) : null;
                $movement = $changes[$ingredient->id] ?? ['incoming' => 0, 'outgoing' => 0, 'correction' => 0];
                $used = $previous ? round($start + $movement['incoming'] - $movement['outgoing'] + $movement['correction'] - $counted, 2) : null;
                if ($used !== null && $used < 0) {
                    throw ValidationException::withMessages(['items' => "Lượng dùng của {$ingredient->name} đang âm. Kiểm tra số đếm và phiếu nhập trước khi chốt."]);
                }
                $txId = null;
                if (round((float) $ingredient->quantity_in_stock, 2) !== round($counted, 2)) {
                    $txId = $inventory->adjustStock([
                        'ingredient_id' => $ingredient->id, 'expected_stock' => $counted,
                        'reference_type' => 'daily_stock_sheet', 'reference_id' => $sheet->id,
                        'note' => "Chốt sổ kho ngày {$date}",
                    ], $request->user()->id)->id;
                }
                DB::table('daily_stock_sheet_items')->where('id', $row->id)->update([
                    'opening_qty' => $start, 'incoming_qty' => $movement['incoming'],
                    'outgoing_qty' => $movement['outgoing'], 'correction_qty' => $movement['correction'],
                    'used_qty' => $used, 'inventory_transaction_id' => $txId, 'updated_at' => now(),
                ]);
            }
            DB::table('daily_stock_sheets')->where('id', $sheet->id)->update([
                'status' => 'CLOSED', 'closed_by' => $request->user()->id,
                'counted_at' => $countedAt, 'closed_at' => $closedAt, 'updated_at' => now(),
            ]);
        });

        return $this->show($date);
    }

    private function validDate(string $date, bool $write = false): void
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 422, 'Ngày không hợp lệ.');
        [$year, $month, $day] = array_map('intval', explode('-', $date));
        abort_unless(checkdate($month, $day, $year), 422, 'Ngày không hợp lệ.');
        abort_if($date > Carbon::now('Asia/Ho_Chi_Minh')->toDateString(), 422, 'Không thể xem sổ cho ngày tương lai.');
        if ($write) abort_unless($date === Carbon::now('Asia/Ho_Chi_Minh')->toDateString(), 422, 'Chỉ được ghi sổ cho ngày hôm nay.');
    }

    private function changes(?string $since, Carbon $until): array
    {
        if (!$since) return [];
        $rows = InventoryTransaction::where('transacted_at', '>', $since)
            ->where('transacted_at', '<=', $until)
            ->where(function ($query) { $query->whereNull('reference_type')->orWhere('reference_type', '!=', 'daily_stock_sheet'); })
            ->get(['id', 'ingredient_id', 'type', 'quantity', 'reference_type', 'reference_id', 'reason_code', 'transacted_at']);
        $importCodes = DB::table('stock_imports')->whereIn('id', $rows->where('reference_type', 'stock_import')->pluck('reference_id')->unique())
            ->pluck('code', 'id');
        $result = [];
        foreach ($rows as $tx) {
            $id = (int) $tx->ingredient_id;
            $result[$id] ??= ['incoming' => 0, 'outgoing' => 0, 'correction' => 0, 'sources' => []];
            $field = match ($tx->type) {
                'IMPORT' => 'incoming', 'EXPORT', 'WASTE' => 'outgoing', default => 'correction',
            };
            $result[$id][$field] += $field === 'outgoing' ? abs((float) $tx->quantity) : (float) $tx->quantity;
            $result[$id]['sources'][] = [
                'transaction_id' => (int) $tx->id,
                'type' => $tx->type,
                'quantity' => (float) $tx->quantity,
                'reference_type' => $tx->reference_type,
                'reference_id' => $tx->reference_id === null ? null : (int) $tx->reference_id,
                'reference_code' => $tx->reference_type === 'stock_import' ? $importCodes->get($tx->reference_id) : null,
                'reason_code' => $tx->reason_code,
            ];
        }
        return $result;
    }

    private function presentItem(object $row): array
    {
        return [
            'ingredient_id' => (int) $row->ingredient_id, 'code' => $row->code,
            'name' => $row->name, 'unit' => $row->unit,
            'category_name' => $row->category_name ?? null,
            'opening_qty' => $row->opening_qty === null ? null : (float) $row->opening_qty,
            'incoming_qty' => (float) $row->incoming_qty, 'outgoing_qty' => (float) $row->outgoing_qty,
            'correction_qty' => (float) $row->correction_qty,
            'counted_qty' => $row->counted_qty === null ? null : (float) $row->counted_qty,
            'used_qty' => $row->used_qty === null ? null : (float) $row->used_qty,
            'explanation' => $row->explanation,
        ];
    }

    private function withExpected(array $item, array $expected, bool $complete, bool $hasOpening): array
    {
        $usage = $expected['by_ingredient'][$item['ingredient_id']] ?? ['quantity' => 0, 'uncomparable_items' => 0];
        $comparable = $hasOpening && $complete && $usage['uncomparable_items'] === 0;
        $item['expected_qty'] = $hasOpening ? $usage['quantity'] : null;
        $item['expected_balance_qty'] = $comparable
            ? round($item['opening_qty'] + $item['incoming_qty'] - $item['outgoing_qty']
                + $item['correction_qty'] - $usage['quantity'], 3)
            : null;
        $item['comparison_complete'] = $comparable;
        $item['uncomparable_items'] = $usage['uncomparable_items'];
        $item['variance_qty'] = $comparable && $item['used_qty'] !== null
            ? round($item['used_qty'] - $usage['quantity'], 3) : null;
        return $item;
    }
}
