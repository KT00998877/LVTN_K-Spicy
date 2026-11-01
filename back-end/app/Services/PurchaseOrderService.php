<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\IngredientPackaging;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseOrderService
{
    public function create(array $data, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $actor) {
            $order = PurchaseOrder::create([
                'code' => $this->nextCode(),
                'supplier_id' => $data['supplier_id'],
                'created_by' => $actor->id,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'requested_delivery_date' => $data['requested_delivery_date'] ?? null,
                'manager_note' => $data['manager_note'] ?? null,
            ]);

            $this->replaceDraftItems($order, $data['items']);

            return $this->load($order);
        });
    }

    public function update(PurchaseOrder $order, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            $this->assertStatus($order, PurchaseOrder::STATUS_DRAFT);

            $order->update([
                'supplier_id' => $data['supplier_id'],
                'requested_delivery_date' => $data['requested_delivery_date'] ?? null,
                'manager_note' => $data['manager_note'] ?? null,
            ]);
            $this->replaceDraftItems($order, $data['items']);

            return $this->load($order);
        });
    }

    public function send(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $order = PurchaseOrder::lockForUpdate()->withCount('items')->findOrFail($order->id);
            $this->assertStatus($order, PurchaseOrder::STATUS_DRAFT);
            if ($order->items_count < 1) {
                throw ValidationException::withMessages(['items' => 'Yêu cầu phải có ít nhất một dòng hàng.']);
            }
            if (! Supplier::whereKey($order->supplier_id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['supplier_id' => 'Nhà cung cấp không còn hoạt động.']);
            }

            $order->update([
                'status' => PurchaseOrder::STATUS_SENT,
                'sent_by' => $actor->id,
                'sent_at' => now(),
            ]);

            return $this->load($order);
        });
    }

    public function cancel(PurchaseOrder $order, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $actor) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            if (! in_array($order->status, [PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SENT], true)) {
                throw ValidationException::withMessages(['status' => 'Chỉ được hủy yêu cầu DRAFT hoặc SENT.']);
            }

            $order->update([
                'status' => PurchaseOrder::STATUS_CANCELLED,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ]);

            return $this->load($order);
        });
    }

    public function accept(PurchaseOrder $order, array $data, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            $this->assertSupplierOwnership($order, $actor);
            $this->assertStatus($order, PurchaseOrder::STATUS_SENT);

            $items = $order->items()->lockForUpdate()->get()->keyBy('id');
            $submitted = collect($data['items'])->keyBy('purchase_order_item_id');
            $this->assertCompleteItemSet($items, $submitted);

            foreach ($items as $item) {
                $input = $submitted->get($item->id);
                $accepted = (int) $input['supplier_accepted_quantity'];
                if ($accepted > $item->requested_purchase_quantity) {
                    throw ValidationException::withMessages([
                        'items' => "Số lượng chấp nhận của {$item->ingredient_id} vượt số lượng yêu cầu.",
                    ]);
                }
                $item->update([
                    'supplier_accepted_quantity' => $accepted,
                    'purchase_unit_price' => $input['purchase_unit_price'],
                    'supplier_note' => $input['supplier_note'] ?? null,
                ]);
            }

            $order->update([
                'status' => PurchaseOrder::STATUS_SUPPLIER_ACCEPTED,
                'supplier_delivery_date' => $data['supplier_delivery_date'],
                'supplier_note' => $data['supplier_note'] ?? null,
                'rejection_reason' => null,
                'supplier_responded_by' => $actor->id,
                'supplier_responded_at' => now(),
            ]);

            return $this->load($order);
        });
    }

    public function reject(PurchaseOrder $order, string $reason, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $reason, $actor) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            $this->assertSupplierOwnership($order, $actor);
            $this->assertStatus($order, PurchaseOrder::STATUS_SENT);
            $order->update([
                'status' => PurchaseOrder::STATUS_SUPPLIER_REJECTED,
                'rejection_reason' => $reason,
                'supplier_responded_by' => $actor->id,
                'supplier_responded_at' => now(),
            ]);

            return $this->load($order);
        });
    }

    public function ship(PurchaseOrder $order, array $data, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            $this->assertSupplierOwnership($order, $actor);
            $this->assertStatus($order, PurchaseOrder::STATUS_SUPPLIER_ACCEPTED);

            $duplicateDocument = PurchaseOrder::where('supplier_id', $order->supplier_id)
                ->where('supplier_document_code', $data['supplier_document_code'])
                ->whereKeyNot($order->id)
                ->exists();
            if ($duplicateDocument) {
                throw ValidationException::withMessages([
                    'supplier_document_code' => 'Mã chứng từ đã được sử dụng.',
                ]);
            }

            $items = $order->items()->lockForUpdate()->get()->keyBy('id');
            $submitted = collect($data['items'])->keyBy('purchase_order_item_id');
            $this->assertCompleteItemSet($items, $submitted);

            foreach ($items as $item) {
                $input = $submitted->get($item->id);
                $shipped = (int) $input['shipped_purchase_quantity'];
                if ($shipped > $item->supplier_accepted_quantity) {
                    throw ValidationException::withMessages([
                        'items' => "Số lượng gửi của {$item->ingredient_id} vượt số lượng đã chấp nhận.",
                    ]);
                }
                $item->update([
                    'shipped_purchase_quantity' => $shipped,
                    'supplier_lot_code' => $input['supplier_lot_code'] ?? null,
                    'expiry_date' => $input['expiry_date'] ?? null,
                    'supplier_note' => $input['supplier_note'] ?? $item->supplier_note,
                ]);
            }

            $order->update([
                'status' => PurchaseOrder::STATUS_SHIPPED,
                'supplier_document_code' => $data['supplier_document_code'],
                'supplier_note' => $data['supplier_note'] ?? $order->supplier_note,
                'shipped_by' => $actor->id,
                'shipped_at' => now(),
            ]);

            return $this->load($order);
        });
    }

    public function present(PurchaseOrder $order, bool $includeReceipt = false): array
    {
        $order->loadMissing(['supplier', 'creator', 'items.ingredient', 'items.packaging']);
        if ($includeReceipt) {
            $order->loadMissing([
                'stockImport.items.ingredient',
                'stockImport.supplierReturns.returner',
                'stockImport.supplierReturns.items.ingredient',
                'stockImport.supplierReturns.transactions',
            ]);
        }

        $data = [
            'id' => (int) $order->id,
            'code' => $order->code,
            'status' => $order->status,
            'supplier_id' => (int) $order->supplier_id,
            'supplier_name' => $order->supplier?->name,
            'created_by' => (int) $order->created_by,
            'created_by_name' => $order->creator?->name,
            'requested_delivery_date' => $order->requested_delivery_date?->toDateString(),
            'supplier_delivery_date' => $order->supplier_delivery_date?->toDateString(),
            'manager_note' => $order->manager_note,
            'supplier_note' => $order->supplier_note,
            'rejection_reason' => $order->rejection_reason,
            'supplier_document_code' => $order->supplier_document_code,
            'sent_by' => $order->sent_by ? (int) $order->sent_by : null,
            'sent_at' => $order->sent_at?->toIso8601String(),
            'supplier_responded_by' => $order->supplier_responded_by ? (int) $order->supplier_responded_by : null,
            'supplier_responded_at' => $order->supplier_responded_at?->toIso8601String(),
            'shipped_by' => $order->shipped_by ? (int) $order->shipped_by : null,
            'shipped_at' => $order->shipped_at?->toIso8601String(),
            'received_by' => $order->received_by ? (int) $order->received_by : null,
            'received_at' => $order->received_at?->toIso8601String(),
            'cancelled_by' => $order->cancelled_by ? (int) $order->cancelled_by : null,
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'items' => $order->items->map(fn (PurchaseOrderItem $item) => [
                'id' => (int) $item->id,
                'ingredient_id' => (int) $item->ingredient_id,
                'ingredient_code' => $item->ingredient?->code,
                'ingredient_name' => $item->ingredient?->name,
                'ingredient_packaging_id' => $item->ingredient_packaging_id ? (int) $item->ingredient_packaging_id : null,
                'purchase_unit' => $item->purchase_unit,
                'conversion_quantity' => (int) $item->conversion_quantity,
                'requested_purchase_quantity' => (int) $item->requested_purchase_quantity,
                'supplier_accepted_quantity' => $item->supplier_accepted_quantity,
                'shipped_purchase_quantity' => $item->shipped_purchase_quantity,
                'purchase_unit_price' => $item->purchase_unit_price === null ? null : (float) $item->purchase_unit_price,
                'supplier_lot_code' => $item->supplier_lot_code,
                'expiry_date' => $item->expiry_date?->toDateString(),
                'supplier_note' => $item->supplier_note,
            ])->values()->all(),
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
        ];

        if ($includeReceipt) {
            $import = $order->stockImport;
            $returnedByImportItem = $import?->supplierReturns
                ->flatMap(fn ($return) => $return->items)
                ->groupBy('stock_import_item_id')
                ->map(fn ($items) => (int) $items->sum('quantity')) ?? collect();
            $data['stock_import'] = $import ? [
                'id' => (int) $import->id,
                'code' => $import->code,
                'status' => $import->status,
                'total_cost' => (float) $import->total_cost,
                'note' => $import->note,
                'received_at' => $import->received_at?->toIso8601String(),
                'confirmed_at' => $import->confirmed_at?->toIso8601String(),
                'items' => $import->items->map(fn ($item) => [
                    'id' => (int) $item->id,
                    'purchase_order_item_id' => (int) $item->purchase_order_item_id,
                    'ingredient_id' => (int) $item->ingredient_id,
                    'ingredient_code' => $item->ingredient?->code,
                    'ingredient_name' => $item->ingredient?->name,
                    'purchase_unit' => $item->purchase_unit,
                    'conversion_quantity' => (int) $item->conversion_quantity,
                    'delivered_purchase_quantity' => (int) $item->delivered_purchase_quantity,
                    'accepted_purchase_quantity' => (int) $item->accepted_purchase_quantity,
                    'rejected_purchase_quantity' => (int) $item->rejected_purchase_quantity,
                    'rejection_reason' => $item->rejection_reason,
                    'quantity' => (int) $item->quantity,
                    'returned_quantity' => (int) ($returnedByImportItem->get($item->id) ?? 0),
                    'returnable_quantity' => max(0, (int) $item->quantity - (int) ($returnedByImportItem->get($item->id) ?? 0)),
                    'unit' => $item->unit,
                    'purchase_unit_price' => (float) $item->purchase_unit_price,
                    'subtotal' => (float) $item->subtotal,
                    'supplier_lot_code' => $item->supplier_lot_code,
                    'expiry_date' => $item->expiry_date?->toDateString(),
                ])->values()->all(),
            ] : null;
            $data['supplier_returns'] = $import ? $import->supplierReturns
                ->sortByDesc('id')
                ->map(fn ($return) => [
                    'id' => (int) $return->id,
                    'code' => $return->code,
                    'stock_import_id' => (int) $return->stock_import_id,
                    'reason' => $return->reason,
                    'returned_by' => (int) $return->returned_by,
                    'returned_by_name' => $return->returner?->name,
                    'returned_at' => $return->returned_at?->toIso8601String(),
                    'items' => $return->items->map(fn ($item) => [
                        'id' => (int) $item->id,
                        'stock_import_item_id' => (int) $item->stock_import_item_id,
                        'ingredient_id' => (int) $item->ingredient_id,
                        'ingredient_code' => $item->ingredient?->code,
                        'ingredient_name' => $item->ingredient?->name,
                        'quantity' => (int) $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'subtotal' => (float) $item->subtotal,
                    ])->values()->all(),
                    'transactions' => $return->transactions->map(fn ($transaction) => [
                        'id' => (int) $transaction->id,
                        'ingredient_id' => (int) $transaction->ingredient_id,
                        'quantity' => (int) $transaction->quantity,
                        'stock_before' => (int) $transaction->stock_before,
                        'stock_after' => (int) $transaction->stock_after,
                    ])->values()->all(),
                ])->values()->all() : [];
        }

        return $data;
    }

    private function replaceDraftItems(PurchaseOrder $order, array $items): void
    {
        $supplier = Supplier::whereKey($order->supplier_id)->where('is_active', true)->firstOrFail();
        $order->items()->delete();

        foreach ($items as $input) {
            $ingredient = Ingredient::whereKey($input['ingredient_id'])
                ->where('supplier_id', $supplier->id)
                ->where('is_active', true)
                ->first();
            if (! $ingredient) {
                throw ValidationException::withMessages([
                    'items' => 'Nguyên liệu không hoạt động hoặc không thuộc nhà cung cấp đã chọn.',
                ]);
            }

            $packaging = null;
            if (! empty($input['ingredient_packaging_id'])) {
                $packaging = IngredientPackaging::whereKey($input['ingredient_packaging_id'])
                    ->where('ingredient_id', $ingredient->id)
                    ->where('supplier_id', $supplier->id)
                    ->where('is_active', true)
                    ->first();
                if (! $packaging) {
                    throw ValidationException::withMessages([
                        'items' => 'Quy cách mua không thuộc nguyên liệu hoặc nhà cung cấp đã chọn.',
                    ]);
                }
            }

            $order->items()->create([
                'ingredient_id' => $ingredient->id,
                'ingredient_packaging_id' => $packaging?->id,
                'purchase_unit' => $packaging?->purchase_unit ?? $ingredient->unit,
                'conversion_quantity' => $packaging?->conversion_quantity ?? 1,
                'requested_purchase_quantity' => $input['requested_purchase_quantity'],
            ]);
        }
    }

    private function assertSupplierOwnership(PurchaseOrder $order, User $actor): void
    {
        if (! $actor->supplier_id || (int) $actor->supplier_id !== (int) $order->supplier_id) {
            abort(404);
        }
    }

    private function assertStatus(PurchaseOrder $order, string $expected): void
    {
        if ($order->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => "Yêu cầu phải ở trạng thái {$expected}.",
            ]);
        }
    }

    private function assertCompleteItemSet($items, $submitted): void
    {
        if ($items->count() !== $submitted->count() || $items->keys()->diff($submitted->keys())->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Phải cung cấp đúng và đủ các dòng hàng của yêu cầu.',
            ]);
        }
    }

    private function load(PurchaseOrder $order): PurchaseOrder
    {
        return $order->fresh(['supplier', 'creator', 'items.ingredient', 'items.packaging']);
    }

    private function nextCode(): string
    {
        $prefix = 'PO-'.now('Asia/Ho_Chi_Minh')->format('Ymd').'-';
        $latest = PurchaseOrder::where('code', 'like', $prefix.'%')->orderByDesc('id')->value('code');
        $next = $latest && preg_match('/(\d+)$/', $latest, $matches)
            ? ((int) $matches[1]) + 1
            : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
