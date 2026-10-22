<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InventoryTransaction;
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ManagerInventoryController — quản lý nguyên liệu và ghi nhận xuất/hủy kho.
 */
class ManagerInventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    public function debugReturn422()
    {
        return response()->json(['message' => 'test'], 422);
    }

    /** GET /api/staff/dashboard/ingredients — danh sách + suppliers lookup */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => DB::table('ingredients as i')
                ->leftJoin('suppliers as s', 's.id', '=', 'i.supplier_id')
                ->select('i.*', 's.name as supplier_name')
                ->orderBy('i.name')->get()->map(function ($row) {
                    foreach (['quantity_in_stock', 'min_stock_level', 'import_price'] as $k) {
                        $row->$k = (float) $row->$k;
                    }
                    $row->is_active = (bool) $row->is_active;
                    return $row;
                }),
            'suppliers' => DB::table('suppliers')->orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    /** POST|PATCH /api/staff/dashboard/ingredients — CRUD cơ bản (giữ tương thích ngược) */
    public function save(Request $request, ?int $id = null): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('ingredients')->ignore($id)],
            'name' => ['required', 'string', 'max:150'],
            'unit' => ['required', 'string', 'max:20'],
            'min_stock_level' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'is_active' => ['required', 'boolean'],
        ]);

        return DB::transaction(function () use ($id, $data) {
            if ($id) {
                $row = DB::table('ingredients')->where('id', $id)->lockForUpdate()->first();
                abort_unless($row, 404);
                if ($data['unit'] !== $row->unit && ($row->quantity_in_stock != 0
                    || DB::table('inventory_transactions')->where('ingredient_id', $id)->exists()
                    || DB::table('menu_item_ingredient')->where('ingredient_id', $id)->exists())) {
                    throw ValidationException::withMessages([
                        'unit' => 'Không đổi đơn vị khi đã có tồn kho, lịch sử hoặc định lượng món.',
                    ]);
                }
                DB::table('ingredients')->where('id', $id)->update($data + ['updated_at' => now()]);
            } else {
                $id = DB::table('ingredients')->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
            }
            return response()->json(['data' => DB::table('ingredients')->find($id)]);
        });
    }

    /**
     * POST /api/staff/dashboard/inventory-transactions — xuất/trả/hủy có lý do.
     */
    public function transact(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'type'          => ['required', Rule::in(['EXPORT', 'WASTE'])],
            'quantity'      => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'reason_code'   => ['required', Rule::in(['SUPPLIER_RETURN', 'OTHER_OUT', 'DAMAGED', 'EXPIRED', 'OTHER_WASTE'])],
            'note'          => ['required', 'string', 'max:2000'],
            'request_key'   => ['required', 'uuid'],
        ]);
        if (($data['type'] === 'EXPORT' && !in_array($data['reason_code'], ['SUPPLIER_RETURN', 'OTHER_OUT'], true))
            || ($data['type'] === 'WASTE' && !in_array($data['reason_code'], ['DAMAGED', 'EXPIRED', 'OTHER_WASTE'], true))) {
            throw ValidationException::withMessages(['reason_code' => 'Lý do không phù hợp với loại phiếu.']);
        }

        // Idempotent replay: nếu request_key đã tồn tại TRƯỚC khi xử lý → trả 200.
        $isReplay = InventoryTransaction::where('request_key', $data['request_key'])->exists();

        $payload = $data + ['performed_by' => $request->user()->id];
        $tx = $this->inventory->decreaseStock($payload, $request->user()->id);

        return response()->json(['data' => $tx], $isReplay ? 200 : 201);
    }

    /** GET /api/staff/dashboard/inventory-transactions — lịch sử + filter */
    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ingredient_id' => ['nullable', 'integer', 'exists:ingredients,id'],
            'type'          => ['nullable', Rule::in(['IMPORT', 'EXPORT', 'WASTE', 'ADJUSTMENT'])],
            'from'          => ['nullable', 'date_format:Y-m-d'],
            'to'            => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $q = DB::table('inventory_transactions as t')
            ->join('ingredients as i', 'i.id', '=', 't.ingredient_id')
            ->leftJoin('users as u', 'u.id', '=', 't.performed_by');
        if (!empty($data['ingredient_id'])) $q->where('t.ingredient_id', $data['ingredient_id']);
        if (!empty($data['type'])) $q->where('t.type', $data['type']);
        if (!empty($data['from'])) $q->where('t.transacted_at', '>=', Carbon::parse($data['from'], 'Asia/Ho_Chi_Minh')->setTimezone(config('app.timezone')));
        if (!empty($data['to'])) $q->where('t.transacted_at', '<', Carbon::parse($data['to'], 'Asia/Ho_Chi_Minh')->addDay()->setTimezone(config('app.timezone')));

        $rows = $q->select('t.*', 'i.code', 'i.name', 'i.unit', 'u.name as performed_name')
                  ->orderByDesc('t.id')->paginate(20);

        $rows->getCollection()->transform(function ($row) {
            $row->transacted_at = Carbon::parse($row->transacted_at)->toIso8601String();
            return $row;
        });
        return response()->json($rows);
    }
}
