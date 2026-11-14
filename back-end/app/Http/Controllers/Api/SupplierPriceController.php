<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierPriceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $isSupplierView = $request->is('api/staff/supplier/prices');
        $supplierId = $isSupplierView ? $request->user()->supplier_id : null;
        if ($isSupplierView) abort_unless($supplierId, 403);
        $ingredients = DB::table('ingredients as i')
            ->join('suppliers as s', 's.id', '=', 'i.supplier_id')
            ->where('i.is_active', true)
            ->when($supplierId, fn ($query) => $query->where('i.supplier_id', $supplierId))
            ->orderBy('i.name')
            ->get(['i.id', 'i.code', 'i.name', 'i.unit', 'i.supplier_id', 'i.supplier_price', 's.name as supplier_name']);

        $rows = [];
        foreach ($ingredients as $ingredient) {
            $packagings = DB::table('ingredient_packagings')
                ->where('ingredient_id', $ingredient->id)
                ->where('supplier_id', $ingredient->supplier_id)
                ->where('is_active', true)->orderBy('id')->get();
            if ($packagings->isEmpty() && $ingredient->unit !== 'gram') {
                $rows[] = [
                    'ingredient_id' => (int) $ingredient->id,
                    'packaging_id' => null,
                    'code' => $ingredient->code,
                    'name' => $ingredient->name,
                    'purchase_unit' => $ingredient->unit,
                    'stock_unit' => $ingredient->unit,
                    'conversion_quantity' => 1,
                    'supplier_name' => $ingredient->supplier_name,
                    'price' => $ingredient->supplier_price === null ? null : (float) $ingredient->supplier_price,
                ];
            }
            foreach ($packagings as $packaging) {
                if ($ingredient->unit === 'gram' && ($packaging->purchase_unit !== 'kg' || (int) $packaging->conversion_quantity !== 1000)) continue;
                $rows[] = [
                    'ingredient_id' => (int) $ingredient->id,
                    'packaging_id' => (int) $packaging->id,
                    'code' => $ingredient->code,
                    'name' => $ingredient->name,
                    'purchase_unit' => $packaging->purchase_unit,
                    'stock_unit' => $ingredient->unit,
                    'conversion_quantity' => (int) $packaging->conversion_quantity,
                    'supplier_name' => $ingredient->supplier_name,
                    'price' => $packaging->supplier_price === null ? null : (float) $packaging->supplier_price,
                ];
            }
        }

        return response()->json(['data' => $rows]);
    }

    public function update(Request $request): JsonResponse
    {
        $supplierId = $request->user()->supplier_id;
        abort_unless($supplierId, 403);
        $data = $request->validate([
            'ingredient_id' => ['required', 'integer', 'exists:ingredients,id'],
            'packaging_id' => ['nullable', 'integer', 'exists:ingredient_packagings,id'],
            'price' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'purchase_unit' => ['required', 'string', 'max:30'],
            'conversion_quantity' => ['required', 'integer', 'min:1', 'max:1000000000'],
        ]);
        $ingredient = DB::table('ingredients')->where('id', $data['ingredient_id'])
            ->where('supplier_id', $supplierId)->where('is_active', true)->first();
        abort_unless($ingredient, 404);
        $unit = $data['purchase_unit'];
        $conversion = (int) $data['conversion_quantity'];
        if (in_array($ingredient->code, ['NL020', 'NL021', 'NL043', 'NL044'], true)) {
            abort_if($unit !== 'thùng' || $conversion !== 24, 422, 'Quy cách mua phải là 1 thùng = 24 lon.');
        } elseif ($ingredient->code === 'NL005') {
            abort_if($unit !== 'bịch' || $conversion !== 24, 422, 'Quy cách mua phải là 1 bịch = 24 cục.');
        } elseif (in_array($ingredient->code, ['NL024', 'NL056'], true)) {
            $requiredUnit = $ingredient->code === 'NL024' ? 'hộp' : 'chai';
            abort_if($unit !== $requiredUnit || $conversion !== 1000, 422, 'Quy cách mua phải là 1 '.$requiredUnit.' = 1.000 ml.');
        } elseif ($ingredient->unit === 'gram') {
            abort_if($unit !== 'kg' || $conversion !== 1000, 422, 'Nguyên liệu tính theo gram phải mua theo kg = 1.000 gram.');
        } elseif ($unit === $ingredient->unit) {
            abort_if($conversion !== 1, 422, 'Mua theo đơn vị tồn kho phải có hệ số quy đổi bằng 1.');
        } else {
            abort_unless(in_array($unit, ['thùng', 'bịch', 'lốc', 'hộp', 'chai', 'kg'], true) && $conversion > 1,
                422, 'Quy cách mua cần có hệ số quy đổi lớn hơn 1.');
        }

        if (!empty($data['packaging_id'])) {
            $packaging = DB::table('ingredient_packagings')->where('id', $data['packaging_id'])
                ->where('ingredient_id', $ingredient->id)->where('supplier_id', $supplierId)
                ->where('is_active', true)->first();
            abort_unless($packaging, 404);
            abort_if($unit === $ingredient->unit, 422, 'Quy cách mua đã tạo không thể đổi về đơn vị tồn kho.');
            abort_if(DB::table('ingredient_packagings')->where('ingredient_id', $ingredient->id)
                ->where('supplier_id', $supplierId)->where('purchase_unit', $unit)
                ->where('id', '!=', $packaging->id)->exists(), 422, 'Quy cách mua này đã tồn tại.');
            DB::table('ingredient_packagings')->where('id', $packaging->id)
                ->update(['purchase_unit' => $unit, 'conversion_quantity' => $conversion,
                    'supplier_price' => $data['price'], 'updated_at' => now()]);
        } else {
            if ($unit === $ingredient->unit) {
                DB::table('ingredients')->where('id', $ingredient->id)
                    ->update(['supplier_price' => $data['price'], 'updated_at' => now()]);
            } else {
                $existing = DB::table('ingredient_packagings')->where('ingredient_id', $ingredient->id)
                    ->where('supplier_id', $supplierId)->where('purchase_unit', $unit)->first();
                if ($existing) {
                    DB::table('ingredient_packagings')->where('id', $existing->id)->update([
                        'conversion_quantity' => $conversion, 'supplier_price' => $data['price'],
                        'is_default' => true, 'is_active' => true, 'updated_at' => now(),
                    ]);
                    $packagingId = $existing->id;
                } else {
                    $packagingId = DB::table('ingredient_packagings')->insertGetId([
                        'ingredient_id' => $ingredient->id, 'supplier_id' => $supplierId,
                        'purchase_unit' => $unit, 'conversion_quantity' => $conversion,
                        'supplier_price' => $data['price'], 'is_default' => true,
                        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }

        DB::table('ingredients')->where('id', $ingredient->id)->where('import_price', '<=', 0)
            ->update(['import_price' => round((float) $data['price'] / $conversion, 2), 'updated_at' => now()]);

        return response()->json(['price' => (float) $data['price'], 'packaging_id' => $packagingId ?? ($packaging->id ?? null)]);
    }
}
