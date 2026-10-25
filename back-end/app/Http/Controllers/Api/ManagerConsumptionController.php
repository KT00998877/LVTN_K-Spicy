<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockRequest;
use App\Services\ConsumptionReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ManagerConsumptionController — báo cáo tiêu hao nguyên liệu + yêu cầu nhập kho.
 *
 * Endpoints (yêu cầu auth:sanctum + role MANAGER):
 *  - GET  /api/staff/manager/consumption?from=&to=&view=menu|ingredient
 *      view=menu      → mỗi món đã bán trừ bao nhiêu nguyên liệu
 *      view=ingredient → mỗi nguyên liệu: tồn hiện tại, đã dùng, còn thiếu, sắp hết
 *  - GET  /api/staff/manager/stock-requests?status=OPEN
 *  - PATCH /api/staff/manager/stock-requests/{id}   (resolve / dismiss)
 */
class ManagerConsumptionController extends Controller
{
    public function __construct(private readonly ConsumptionReportService $consumption)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to'   => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'view' => ['sometimes', 'string', 'in:menu,ingredient'],
        ]);

        $today = Carbon::now('Asia/Ho_Chi_Minh')->toDateString();
        $from = $data['from'] ?? Carbon::now('Asia/Ho_Chi_Minh')->subDays(6)->toDateString();
        $to   = $data['to'] ?? $today;
        abort_if($to > $today, 422, 'Không chọn ngày trong tương lai.');
        abort_if((strtotime($to) - strtotime($from)) / 86400 > 366, 422, 'Khoảng báo cáo không quá 366 ngày.');

        $view = $data['view'] ?? 'menu';

        return response()->json([
            'success' => true,
            'data'    => [
                'from'        => $from,
                'to'          => $to,
                'view'        => $view,
                'menu_items'  => $view === 'menu' ? $this->consumption->byMenuItem($from, $to) : [],
                'ingredients' => $view === 'ingredient' ? $this->consumption->byIngredient($from, $to) : [],
            ],
        ]);
    }

    /**
     * Danh sách yêu cầu nhập kho do bếp gửi.
     */
    public function stockRequests(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'string', 'in:OPEN,RESOLVED,DISMISSED'],
        ]);

        $q = StockRequest::with(['items.ingredient', 'requester', 'resolver'])->orderByDesc('id');
        if (!empty($data['status'])) $q->where('status', $data['status']);

        $requests = $q->limit(100)->get();

        return response()->json([
            'success' => true,
            'data'    => $requests->map(fn (StockRequest $r) => [
                'id'            => (int) $r->id,
                'code'          => $r->code,
                'menu_item_id'  => $r->menu_item_id ? (int) $r->menu_item_id : null,
                'menu_item_name' => $r->menu_item_name,
                'status'        => $r->status,
                'requested_by'  => $r->requested_by ? (int) $r->requested_by : null,
                'requested_by_name' => $r->requester?->name,
                'resolved_by_name'  => $r->resolver?->name,
                'resolved_at'   => $r->resolved_at?->toIso8601String(),
                'resolution_note' => $r->resolution_note,
                'note'          => $r->note,
                'created_at'    => $r->created_at?->toIso8601String(),
                'items'         => $r->items->map(fn ($i) => [
                    'ingredient_id'    => (int) $i->ingredient_id,
                    'ingredient_name'  => $i->ingredient?->name,
                    'unit'             => $i->unit,
                    'required_qty'     => (float) $i->required_qty,
                    'available_qty'    => (float) $i->available_qty,
                    'shortage_qty'     => (float) $i->shortage_qty,
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    /**
     * Chốt xử lý một yêu cầu nhập kho.
     */
    public function resolveStockRequest(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', 'in:RESOLVED,DISMISSED'],
            'note'   => ['nullable', 'string', 'max:500'],
        ]);

        $stockRequest = StockRequest::where('id', $id)->first();
        if (!$stockRequest) {
            abort(404, 'Không tìm thấy yêu cầu nhập kho.');
        }
        if ($stockRequest->status !== StockRequest::STATUS_OPEN) {
            abort(409, 'Yêu cầu này đã được xử lý.');
        }

        $stockRequest->update([
            'status'          => $data['status'],
            'resolved_by'     => $request->user()->id,
            'resolved_at'     => now(),
            'resolution_note' => $data['note'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data'    => ['id' => (int) $stockRequest->id, 'status' => $stockRequest->status],
        ]);
    }
}
