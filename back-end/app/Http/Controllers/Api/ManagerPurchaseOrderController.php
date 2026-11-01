<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePurchaseOrderRequest;
use App\Http\Requests\UpdatePurchaseOrderReceiptRequest;
use App\Models\PurchaseOrder;
use App\Services\PurchaseOrderReceiptService;
use App\Services\PurchaseOrderService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManagerPurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrders,
        private readonly PurchaseOrderReceiptService $receipts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([
                PurchaseOrder::STATUS_DRAFT,
                PurchaseOrder::STATUS_SENT,
                PurchaseOrder::STATUS_SUPPLIER_ACCEPTED,
                PurchaseOrder::STATUS_SUPPLIER_REJECTED,
                PurchaseOrder::STATUS_SHIPPED,
                PurchaseOrder::STATUS_RECEIVED,
                PurchaseOrder::STATUS_CANCELLED,
            ])],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PurchaseOrder::with([
            'supplier', 'creator', 'items.ingredient', 'items.packaging',
            'stockImport.items.ingredient',
            'stockImport.supplierReturns.returner',
            'stockImport.supplierReturns.items.ingredient',
            'stockImport.supplierReturns.transactions',
        ])
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['q'] ?? null, function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('code', 'like', "%{$search}%")
                        ->orWhere('supplier_document_code', 'like', "%{$search}%")
                        ->orWhereHas('stockImport', fn ($stockImport) => $stockImport->where('code', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('id');
        $page = $query->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (PurchaseOrder $order) => $this->purchaseOrders->present($order, true))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $order = $this->purchaseOrders->create($request->validated(), $request->user());

        return response()->json(['data' => $this->purchaseOrders->present($order, true)], 201);
    }

    public function show(int $id): JsonResponse
    {
        $order = PurchaseOrder::with([
            'supplier', 'creator', 'items.ingredient', 'items.packaging',
            'stockImport.items.ingredient',
            'stockImport.supplierReturns.returner',
            'stockImport.supplierReturns.items.ingredient',
            'stockImport.supplierReturns.transactions',
        ])->findOrFail($id);

        return response()->json(['data' => $this->purchaseOrders->present($order, true)]);
    }

    public function update(StorePurchaseOrderRequest $request, int $id): JsonResponse
    {
        $order = $this->purchaseOrders->update(PurchaseOrder::findOrFail($id), $request->validated());

        return response()->json(['data' => $this->purchaseOrders->present($order, true)]);
    }

    public function send(Request $request, int $id): JsonResponse
    {
        $order = $this->purchaseOrders->send(PurchaseOrder::findOrFail($id), $request->user());

        return response()->json(['data' => $this->purchaseOrders->present($order, true)]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $order = $this->purchaseOrders->cancel(PurchaseOrder::findOrFail($id), $request->user());

        return response()->json(['data' => $this->purchaseOrders->present($order, true)]);
    }

    public function createReceipt(Request $request, int $id): JsonResponse
    {
        $order = $this->receipts->createDraft(PurchaseOrder::findOrFail($id), $request->user());

        return response()->json(['data' => $this->purchaseOrders->present($order, true)], 201);
    }

    public function updateReceipt(UpdatePurchaseOrderReceiptRequest $request, int $id): JsonResponse
    {
        $order = $this->receipts->updateDraft(PurchaseOrder::findOrFail($id), $request->validated());

        return response()->json(['data' => $this->purchaseOrders->present($order, true)]);
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['received_at' => ['required', 'date_format:Y-m-d H:i:s']]);
        $receivedAt = Carbon::createFromFormat('Y-m-d H:i:s', $data['received_at'], 'Asia/Ho_Chi_Minh');
        if ($receivedAt->isFuture()) {
            throw ValidationException::withMessages(['received_at' => 'Giờ nhận hàng không được ở tương lai.']);
        }
        $latestCount = DB::table('daily_stock_sheets')->where('status', 'CLOSED')->orderByDesc('closed_at')->value('counted_at');
        if ($latestCount && $receivedAt->lessThanOrEqualTo(Carbon::parse($latestCount))) {
            throw ValidationException::withMessages([
                'received_at' => 'Hàng nhận trước hoặc đúng lần đếm kho đã chốt. Cần đối chiếu với sổ đó trước khi ghi nhập.',
            ]);
        }

        $order = $this->receipts->confirm(PurchaseOrder::findOrFail($id), $request->user(), $receivedAt);

        return response()->json(['data' => $this->purchaseOrders->present($order, true)]);
    }

}
