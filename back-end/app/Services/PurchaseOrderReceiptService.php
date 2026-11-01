<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\StockImport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderReceiptService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function createDraft(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            $existing = StockImport::where('purchase_order_id', $order->id)->first();
            if ($existing) {
                return $this->load($order);
            }
            $this->assertReceivable($order);

            if ($order->supplier_document_code) {
                $duplicateDocument = StockImport::where('supplier_id', $order->supplier_id)
                    ->where('supplier_document_code', $order->supplier_document_code)
                    ->exists();
                if ($duplicateDocument) {
                    throw ValidationException::withMessages([
                        'supplier_document_code' => 'Mã chứng từ nhà cung cấp đã được dùng cho phiếu nhập khác.',
                    ]);
                }
            }

            $import = StockImport::create([
                'code' => $this->nextCode(),
                'purchase_order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'supplier_document_code' => $order->supplier_document_code,
                'imported_by' => $actor->id,
                'imported_at' => now('Asia/Ho_Chi_Minh')->toDateString(),
                'status' => StockImport::STATUS_DRAFT,
                'total_cost' => 0,
                'note' => 'Kiểm nhận từ yêu cầu '.$order->code,
            ]);

            $totalCost = 0.0;
            foreach ($order->items()->with('ingredient')->lockForUpdate()->orderBy('ingredient_id')->get() as $item) {
                $shipped = (int) $item->shipped_purchase_quantity;
                $conversion = (int) $item->conversion_quantity;
                $purchasePrice = (float) $item->purchase_unit_price;
                $quantity = $this->stockQuantity($shipped, $conversion);
                $subtotal = round($shipped * $purchasePrice, 2);
                $totalCost += $subtotal;

                $import->items()->create([
                    'purchase_order_item_id' => $item->id,
                    'ingredient_id' => $item->ingredient_id,
                    'ingredient_packaging_id' => $item->ingredient_packaging_id,
                    'quantity' => $quantity,
                    'unit' => $item->ingredient->unit,
                    'unit_price' => round($purchasePrice / $conversion, 2),
                    'purchase_quantity' => $shipped,
                    'purchase_unit' => $item->purchase_unit,
                    'conversion_quantity' => $conversion,
                    'purchase_unit_price' => $purchasePrice,
                    'delivered_purchase_quantity' => $shipped,
                    'accepted_purchase_quantity' => $shipped,
                    'rejected_purchase_quantity' => 0,
                    'subtotal' => $subtotal,
                    'expiry_date' => $item->expiry_date,
                    'supplier_lot_code' => $item->supplier_lot_code,
                    'note' => $item->supplier_note,
                ]);
            }

            $import->update(['total_cost' => round($totalCost, 2)]);

            return $this->load($order);
        });
    }

    public function updateDraft(PurchaseOrder $order, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            $this->assertReceivable($order);

            $import = StockImport::where('purchase_order_id', $order->id)->lockForUpdate()->firstOrFail();
            if ($import->status !== StockImport::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => 'Phiếu kiểm nhận đã được xác nhận và không thể sửa.']);
            }

            $items = $import->items()->lockForUpdate()->get()->keyBy('purchase_order_item_id');
            $submitted = collect($data['items'])->keyBy('purchase_order_item_id');
            if ($items->count() !== $submitted->count() || $items->keys()->diff($submitted->keys())->isNotEmpty()) {
                throw ValidationException::withMessages(['items' => 'Phải kiểm nhận đúng và đủ các dòng hàng.']);
            }

            $totalCost = 0.0;
            foreach ($items as $purchaseOrderItemId => $item) {
                $input = $submitted->get($purchaseOrderItemId);
                $delivered = (int) $input['delivered_purchase_quantity'];
                $accepted = (int) $input['accepted_purchase_quantity'];
                if ($accepted > $delivered) {
                    throw ValidationException::withMessages([
                        'items' => "Số lượng chấp nhận của dòng {$purchaseOrderItemId} không được vượt số thực nhận.",
                    ]);
                }

                $rejected = $delivered - $accepted;
                $rejectionReason = trim((string) ($input['rejection_reason'] ?? ''));
                if ($rejected > 0 && $rejectionReason === '') {
                    throw ValidationException::withMessages([
                        'items' => "Dòng {$purchaseOrderItemId} có hàng từ chối nên phải nhập lý do.",
                    ]);
                }

                $conversion = (int) $item->conversion_quantity;
                $quantity = $this->stockQuantity($accepted, $conversion);
                $purchasePrice = (float) $item->purchase_unit_price;
                $subtotal = round($accepted * $purchasePrice, 2);
                $totalCost += $subtotal;

                $item->update([
                    'purchase_quantity' => $accepted,
                    'quantity' => $quantity,
                    'delivered_purchase_quantity' => $delivered,
                    'accepted_purchase_quantity' => $accepted,
                    'rejected_purchase_quantity' => $rejected,
                    'rejection_reason' => $rejected > 0 ? $rejectionReason : null,
                    'subtotal' => $subtotal,
                    'expiry_date' => $input['expiry_date'] ?? null,
                    'supplier_lot_code' => $input['supplier_lot_code'] ?? null,
                ]);
            }

            $import->update([
                'total_cost' => round($totalCost, 2),
                'note' => $data['note'] ?? $import->note,
            ]);

            return $this->load($order);
        });
    }

    public function confirm(PurchaseOrder $order, User $actor, Carbon $receivedAt): PurchaseOrder
    {
        $import = StockImport::where('purchase_order_id', $order->id)->firstOrFail();
        $this->inventory->confirmImport($import, (int) $actor->id, $receivedAt);

        return $this->load($order);
    }

    private function assertReceivable(PurchaseOrder $order): void
    {
        if ($order->status !== PurchaseOrder::STATUS_SHIPPED) {
            throw ValidationException::withMessages(['status' => 'Chỉ yêu cầu SHIPPED mới được kiểm nhận.']);
        }
    }

    private function stockQuantity(int $purchaseQuantity, int $conversion): int
    {
        $quantity = $purchaseQuantity * $conversion;
        if ($quantity > 9_999_999_999) {
            throw ValidationException::withMessages(['items' => 'Số lượng quy đổi vượt giới hạn tồn kho.']);
        }

        return $quantity;
    }

    private function nextCode(): string
    {
        $prefix = 'PN-'.now('Asia/Ho_Chi_Minh')->format('Ymd').'-';
        $latest = StockImport::where('code', 'like', $prefix.'%')->orderByDesc('id')->value('code');
        $next = $latest && preg_match('/(\d+)$/', $latest, $matches)
            ? ((int) $matches[1]) + 1
            : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function load(PurchaseOrder $order): PurchaseOrder
    {
        return $order->fresh([
            'supplier', 'creator', 'items.ingredient', 'items.packaging',
            'stockImport.items.ingredient',
        ]);
    }
}
