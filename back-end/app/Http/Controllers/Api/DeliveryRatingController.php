<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\DeliveryRatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * DeliveryRatingController — đánh giá dịch vụ giao hàng.
 *
 *  - POST /api/orders/me/{id}/delivery-rating   : customer (chấm điểm + nhận xét + ảnh)
 *  - GET  /api/staff/manager/delivery-ratings   : manager (thống kê breakdown theo shipper)
 */
class DeliveryRatingController extends Controller
{
    public function __construct(private readonly DeliveryRatingService $service)
    {
    }

    /**
     * POST /api/orders/me/{id}/delivery-rating  (multipart/form-data)
     * Body: rating (1-5), comment?, photos[]?
     */
    public function submit(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'rating'   => ['required', 'integer', 'between:'.DeliveryRatingService::RATING_MIN.','.DeliveryRatingService::RATING_MAX],
            'comment'  => ['nullable', 'string', 'max:'.DeliveryRatingService::COMMENT_MAX],
            'photos'   => ['nullable', 'array', 'max:'.DeliveryRatingService::MAX_PHOTOS],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.DeliveryRatingService::MAX_PHOTO_KB],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();
        $order = Order::where('customer_id', $user->id)->findOrFail($id);

        $photos = $request->file('photos', []) ?? [];
        $this->service->submit($user, $order, $data, $photos);

        $order->load('deliveryPhotos');

        return response()->json([
            'success' => true,
            'message' => 'Cảm ơn bạn đã đánh giá dịch vụ giao hàng!',
            'data'    => $this->service->formatForOrder($order),
        ]);
    }

    /**
     * GET /api/staff/manager/delivery-ratings  (MANAGER)
     * Query: ?shipper_id=123&limit=20
     */
    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shipper_id' => ['nullable', 'integer', 'exists:users,id'],
            'limit'      => ['nullable', 'integer', 'between:1,100'],
        ]);

        $summary = $this->service->summary(
            isset($data['shipper_id']) ? (int) $data['shipper_id'] : null,
            (int) ($data['limit'] ?? 20),
        );

        return response()->json([
            'success' => true,
            'data'    => $summary,
        ]);
    }
}
