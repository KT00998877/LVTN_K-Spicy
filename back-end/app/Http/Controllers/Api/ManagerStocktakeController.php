<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreStocktakeRequest;
use App\Models\{Ingredient, Stocktake, StocktakeItem};
use App\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManagerStocktakeController extends Controller
{
    public function __construct(private readonly InventoryService $inventory)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status'   => ['nullable', 'string', 'in:OPEN,COMPLETED,CANCELLED'],
            'from'     => ['nullable', 'date_format:Y-m-d'],
            'to'       => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q'        => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $q = Stocktake::with(['staff', 'completer', 'items.ingredient']);
        if (!empty($filters['status'])) $q->where('status', $filters['status']);
        if (!empty($filters['from']))   $q->where('counted_at', '>=', $filters['from']);
        if (!empty($filters['to']))     $q->where('counted_at', '<=', $filters['to']);
        if (!empty($filters['q']))      $q->where('code', 'like', '%'.$filters['q'].'%');

        $page = $q->orderByDesc('id')->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($s) => $this->present($s))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
            ],
        ]);
    }

    public function store(StoreStocktakeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $st = DB::transaction(function () use ($data, $request) {
            $header = Stocktake::create([
                'code'        => $this->nextCode(),
                'counted_at'  => $data['counted_at'],
                'staff_id'    => $request->user()->id,
                'status'      => Stocktake::STATUS_OPEN,
                'note'        => $data['note'] ?? null,
                'total_difference_value' => 0,
            ]);
            foreach ($data['items'] as $it) {
                $ing = Ingredient::findOrFail($it['ingredient_id']);
                StocktakeItem::create([
                    'stocktake_id'    => $header->id,
                    'ingredient_id'   => $ing->id,
                    'system_qty'      => (float) $ing->quantity_in_stock, // snapshot khi mở phiên
                    'actual_qty'      => (float) $it['actual_qty'],
                    'difference_qty'  => round(((float) $it['actual_qty']) - ((float) $ing->quantity_in_stock), 2),
                    'unit_cost'       => (float) $ing->import_price,
                    'difference_value'=> round((((float) $it['actual_qty']) - ((float) $ing->quantity_in_stock)) * (float) $ing->import_price, 2),
                    'reason'          => $it['reason'] ?? null,
                ]);
            }
            return $header;
        });

        return response()->json(['data' => $this->present($st->fresh(['items.ingredient']))], 201);
    }

    public function show(int $id): JsonResponse
    {
        $st = Stocktake::with(['staff', 'completer', 'items.ingredient'])->findOrFail($id);
        return response()->json(['data' => $this->present($st)]);
    }

    public function complete(int $id, Request $request): JsonResponse
    {
        /** @var Stocktake $st */
        $st = Stocktake::findOrFail($id);
        $this->inventory->completeStocktake($st, (int) $request->user()->id);
        return response()->json(['data' => $this->present($st->fresh(['items.ingredient', 'completer']))]);
    }

    public function cancel(int $id, Request $request): JsonResponse
    {
        return DB::transaction(function () use ($id, $request) {
            /** @var Stocktake $st */
            $st = Stocktake::lockForUpdate()->findOrFail($id);
            if ($st->status === Stocktake::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'status' => 'Không thể huỷ đợt kiểm kê đã hoàn tất.',
                ]);
            }
            $st->update([
                'status'              => Stocktake::STATUS_CANCELLED,
                'cancelled_by'        => $request->user()->id,
                'cancelled_at'        => now(),
                'cancellation_reason' => 'Huỷ phiên kiểm kê',
            ]);
            return response()->json(['data' => $this->present($st->fresh())]);
        });
    }

    private function nextCode(): string
    {
        $today = Carbon::now('Asia/Ho_Chi_Minh')->format('Ymd');
        $prefix = "KK-{$today}-";
        $latest = Stocktake::where('code', 'like', $prefix.'%')->orderByDesc('id')->value('code');
        $next = 1;
        if ($latest && preg_match('/(\d+)$/', $latest, $m)) $next = ((int) $m[1]) + 1;
        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function present(Stocktake $st): array
    {
        return [
            'id'                       => (int) $st->id,
            'code'                     => $st->code,
            'counted_at'               => $st->counted_at?->toDateString(),
            'status'                   => $st->status,
            'staff_id'                 => $st->staff_id ? (int) $st->staff_id : null,
            'staff_name'               => $st->staff?->name,
            'completed_by'             => $st->completed_by ? (int) $st->completed_by : null,
            'completed_by_name'        => $st->completer?->name,
            'completed_at'             => $st->completed_at?->toIso8601String(),
            'total_difference_value'   => (float) $st->total_difference_value,
            'note'                     => $st->note,
            'cancellation_reason'      => $st->cancellation_reason,
            'items' => $st->items->map(fn ($it) => [
                'id'                    => (int) $it->id,
                'ingredient_id'         => (int) $it->ingredient_id,
                'ingredient_code'       => $it->ingredient?->code,
                'ingredient_name'       => $it->ingredient?->name,
                'unit'                  => $it->ingredient?->unit,
                'system_qty'            => (float) $it->system_qty,
                'actual_qty'            => (float) $it->actual_qty,
                'difference_qty'        => (float) $it->difference_qty,
                'unit_cost'             => (float) $it->unit_cost,
                'difference_value'      => (float) $it->difference_value,
                'reason'                => $it->reason,
                'inventory_transaction_id' => $it->inventory_transaction_id,
            ])->all(),
            'created_at' => $st->created_at?->toIso8601String(),
            'updated_at' => $st->updated_at?->toIso8601String(),
        ];
    }
}
