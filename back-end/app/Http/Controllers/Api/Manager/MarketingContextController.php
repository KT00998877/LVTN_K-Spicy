<?php

namespace App\Http\Controllers\Api\Manager;

use App\Http\Controllers\Controller;
use App\Services\Marketing\MarketingService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/manager/marketing/context
 *
 * Trả về context "đề xuất" cho form tạo asset:
 *  - Brand + store info
 *  - Campaigns đang active
 *  - Menu items phổ biến
 *  - Promotions đang chạy
 *  - Tones / channels / content_types hợp lệ
 */
class MarketingContextController extends Controller
{
    public function __construct(private readonly MarketingService $marketing) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->marketing->buildDraftContext(),
        ]);
    }
}