<?php

namespace Tests\Feature;

use App\Http\Requests\StorePurchaseOrderRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class PurchaseOrderDeliveryDateTest extends TestCase
{
    public function test_purchase_order_dates_use_the_vietnam_calendar_day(): void
    {
        Carbon::setTestNow('2026-11-17 17:00:00 UTC'); // 00:00 on 18 November in Vietnam.
        try {
            $field = 'requested_delivery_date';
            $rules = (new StorePurchaseOrderRequest())->rules();
            $this->assertTrue(Validator::make([$field => '2026-11-17'], $rules)->errors()->has($field));
            $this->assertFalse(Validator::make([$field => '2026-11-18'], $rules)->errors()->has($field));
        } finally {
            Carbon::setTestNow();
        }
    }
}
