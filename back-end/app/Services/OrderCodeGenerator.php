<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

class OrderCodeGenerator
{
    /** Called inside the order creation transaction, shared by staff and customer checkout. */
    public function next(): string
    {
        $date = now()->toDateString();
        DB::table('order_code_sequences')->insertOrIgnore(['date' => $date, 'sequence' => 0]);
        $row = DB::table('order_code_sequences')->where('date', $date)->lockForUpdate()->first();
        $sequence = (int) $row->sequence;
        do {
            $code = sprintf('ORD-%s-%04d', str_replace('-', '', $date), ++$sequence);
            // Preserve legacy codes created before the sequence table existed.
        } while (Order::where('code', $code)->exists());
        DB::table('order_code_sequences')->where('date', $date)->update(['sequence' => $sequence]);
        return $code;
    }
}
