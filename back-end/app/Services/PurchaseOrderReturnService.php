<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\StockImport;
use App\Models\StockImportItem;
use App\Models\SupplierReturn;
use App\Models\SupplierReturnItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class PurchaseOrderReturnService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function create(PurchaseOrder $order, array $data, User $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            $order = PurchaseOrder::lockForUpdate()->findOrFail($order->id);
            $existing = SupplierReturn::with('items')->where('request_key', $data['request_key'])->first();
            if ($existing) {
                $belongsToOrder = StockImport::whereKey($existing->stock_import_id)
                    ->where('purchase_order_id', $order->id)
                    ->exists();
                abort_unless($belongsToOrder, 409, 'Mã yêu cầu đã được sử dụng cho chứng từ khác.');
                $submitted = collect($data['items'])
                    ->mapWithKeys(fn ($item) => [(int) $item['stock_import_item_id'] => (int) $item['quantity']])
                    ->sortKeys()
                    ->all();
                $recorded = $existing->items
                    ->mapWithKeys(fn ($item) => [(int) $item->stock_import_item_id => (int) $item->quantity])
                    ->sortKeys()
                    ->all();
                abort_unless(trim($existing->reason) === trim($data['reason']) && $submitted === $recorded, 409, 'Mã yêu cầu đã được sử dụng với nội dung khác.');

                return $this->load($order);
            }

            if ($order->status !== PurchaseOrder::STATUS_RECEIVED) {
                throw ValidationException::withMessages([
                    'status' => 'Chỉ có thể trả hàng từ yêu cầu đã nhận và nhập kho.',
                ]);
            }

            $import = StockImport::where('purchase_order_id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($import->status !== StockImport::STATUS_CONFIRMED) {
                throw ValidationException::withMessages([
                    'status' => 'Phiếu nhập gốc chưa được xác nhận.',
                ]);
            }

            $submitted = collect($data['items'])->keyBy('stock_import_item_id');
            $items = StockImportItem::where('stock_import_id', $import->id)
                ->whereIn('id', $submitted->keys())
                ->orderBy('ingredient_id')
                ->lockForUpdate()
                ->get();
            if ($items->count() !== $submitted->count()) {
                throw ValidationException::withMessages([
                    'items' => 'Dòng hàng trả không thuộc phiếu nhập gốc.',
                ]);
            }

            $return = SupplierReturn::create([
                'code' => 'TMP-'.substr(str_replace('-', '', $data['request_key']), 0, 20),
                'stock_import_id' => $import->id,
                'request_key' => $data['request_key'],
                'reason' => trim($data['reason']),
                'returned_by' => $actor->id,
                'returned_at' => now(),
            ]);
            $return->update(['code' => sprintf('THN-%s-%05d', now()->format('Ymd'), $return->id)]);

            foreach ($items as $item) {
                $quantity = (int) $submitted->get($item->id)['quantity'];
                $alreadyReturned = (int) SupplierReturnItem::where('stock_import_item_id', $item->id)->sum('quantity');
                $remaining = (int) $item->quantity - $alreadyReturned;
                if ($quantity > $remaining) {
                    throw ValidationException::withMessages([
                        'items' => "Số lượng trả của dòng {$item->id} vượt lượng đã nhập còn có thể trả ({$remaining}).",
                    ]);
                }

                $unitPrice = (float) $item->unit_price;
                SupplierReturnItem::create([
                    'supplier_return_id' => $return->id,
                    'stock_import_item_id' => $item->id,
                    'ingredient_id' => $item->ingredient_id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => round($quantity * $unitPrice, 2),
                ]);

                $this->inventory->decreaseStock([
                    'ingredient_id' => $item->ingredient_id,
                    'quantity' => $quantity,
                    'type' => 'EXPORT',
                    'reason_code' => 'SUPPLIER_RETURN',
                    'reference_type' => 'supplier_return',
                    'reference_id' => $return->id,
                    'note' => sprintf('Trả nhà cung cấp từ %s: %s', $import->code, trim($data['reason'])),
                    'request_key' => Uuid::uuid5(
                        Uuid::NAMESPACE_DNS,
                        sprintf('supplier_return:%d:item:%d', $return->id, $item->id),
                    )->toString(),
                ], $actor->id);
            }

            return $this->load($order);
        });
    }

    private function load(PurchaseOrder $order): PurchaseOrder
    {
        return $order->fresh([
            'supplier', 'creator', 'items.ingredient', 'items.packaging',
            'stockImport.items.ingredient',
            'stockImport.supplierReturns.returner',
            'stockImport.supplierReturns.items.ingredient',
            'stockImport.supplierReturns.transactions',
        ]);
    }
}
