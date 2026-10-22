<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\{MenuItem, Review};
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ReviewController — quản lý review món ăn.
 *
 * Phân quyền:
 *  - GET    /api/menu-items/{id}/reviews         : public (chỉ approved + visible)
 *  - GET    /api/menu-items/{id}/reviews/summary : public (avg + count + breakdown)
 *  - GET    /api/reviews/menu-item/{id}/eligibility : customer (kiểm tra đủ điều kiện)
 *  - GET    /api/reviews/me                      : customer (review của tôi)
 *  - POST   /api/reviews                         : customer (tạo, multipart cho photos[])
 *  - PUT    /api/reviews/{id}                    : customer (sửa review của mình)
 *  - DELETE /api/reviews/{id}                    : customer (xóa review của mình)
 *  - PATCH  /api/reviews/{id}/hide               : manager (ẩn/hiện)
 *  - GET    /api/reviews                         : manager (liệt kê tất cả, kể cả ẩn)
 */
class ReviewController extends Controller
{
    public function __construct(private readonly ReviewService $reviewService)
    {
    }

    /**
     * GET /api/menu-items/{id}/reviews
     * Query: ?per_page=10&page=1
     */
    public function indexForMenuItem(Request $request, int $menuItemId): JsonResponse
    {
        MenuItem::findOrFail($menuItemId);
        $userId = optional($request->user())->id;
        $payload = $this->reviewService->listForMenuItem(
            $menuItemId,
            (int) $request->query('per_page', 10),
            $userId,
        );
        $summary = $this->reviewService->summaryForMenuItem($menuItemId);

        return response()->json([
            'success' => true,
            'data'    => [
                'items'   => $payload['items'],
                'meta'    => $payload['meta'],
                'summary' => $summary,
            ],
        ]);
    }

    /**
     * GET /api/menu-items/{id}/reviews/summary
     */
    public function summaryForMenuItem(int $menuItemId): JsonResponse
    {
        MenuItem::findOrFail($menuItemId);
        return response()->json([
            'success' => true,
            'data'    => $this->reviewService->summaryForMenuItem($menuItemId),
        ]);
    }

    /**
     * GET /api/reviews/menu-item/{id}/eligibility
     * Customer: kiểm tra có đủ điều kiện review món này không, kèm order đã mua.
     */
    public function eligibility(Request $request, int $menuItemId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $menuItem = MenuItem::findOrFail($menuItemId);
        $order = $this->reviewService->findEligibleOrder($user, $menuItem);
        $existing = Review::where('user_id', $user->id)
            ->where('menu_item_id', $menuItemId)->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'can_review'  => $order !== null && $existing === null,
                'has_reviewed' => $existing !== null,
                'order_id'     => $order?->id,
                'order_code'   => $order?->code,
                'completed_at' => $order?->completed_at?->toIso8601String(),
                'existing_review' => $existing ? [
                    'id'      => (int) $existing->id,
                    'rating'  => (int) $existing->rating,
                    'comment' => $existing->comment,
                    'photos'  => $existing->photos->map(fn ($p) => [
                        'id'  => (int) $p->id,
                        'url' => $p->url,
                    ])->values()->all(),
                ] : null,
            ],
        ]);
    }

    /**
     * GET /api/reviews/me
     */
    public function myReviews(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $payload = $this->reviewService->listForUser($user, (int) $request->query('per_page', 20));
        return response()->json([
            'success' => true,
            'data'    => $payload,
        ]);
    }

    /**
     * POST /api/reviews (multipart/form-data)
     * Body: menu_item_id, rating, comment?, photos[]?
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'menu_item_id' => ['required', 'integer', 'exists:menu_items,id'],
            'rating'       => ['required', 'integer', 'between:'.ReviewService::RATING_MIN.','.ReviewService::RATING_MAX],
            'comment'      => ['nullable', 'string', 'max:'.ReviewService::COMMENT_MAX],
            'photos'       => ['nullable', 'array', 'max:'.ReviewService::MAX_PHOTOS],
            'photos.*'     => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.ReviewService::MAX_PHOTO_KB],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();
        $photos = $request->file('photos', []) ?? [];
        $review = $this->reviewService->create($user, $data, $photos);

        return response()->json([
            'success' => true,
            'message' => 'Cảm ơn bạn đã đánh giá món ăn!',
            'data'    => $this->reviewService->format($review),
        ], 201);
    }

    /**
     * PUT /api/reviews/{id} (multipart/form-data)
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $review = Review::findOrFail($id);

        $data = $request->validate([
            'rating'   => ['sometimes', 'integer', 'between:'.ReviewService::RATING_MIN.','.ReviewService::RATING_MAX],
            'comment'  => ['nullable', 'string', 'max:'.ReviewService::COMMENT_MAX],
            'photos'   => ['sometimes', 'array', 'max:'.ReviewService::MAX_PHOTOS],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.ReviewService::MAX_PHOTO_KB],
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();
        // photos = null → giữ nguyên. photos = [] rỗng → xóa hết ảnh.
        $photos = $request->has('photos') ? ($request->file('photos', []) ?? []) : null;
        $review = $this->reviewService->update($user, $review, $data, $photos);

        return response()->json([
            'success' => true,
            'message' => 'Đã cập nhật đánh giá.',
            'data'    => $this->reviewService->format($review),
        ]);
    }

    /**
     * DELETE /api/reviews/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $review = Review::findOrFail($id);
        /** @var \App\Models\User $user */
        $user = $request->user();
        $this->reviewService->delete($user, $review);
        return response()->json([
            'success' => true,
            'message' => 'Đã xóa đánh giá.',
        ]);
    }

    /* ---------------------------------------------------------- */
    /* Manager                                                      */
    /* ---------------------------------------------------------- */

    /**
     * GET /api/reviews  (MANAGER)
     * Liệt kê tất cả review (kể cả ẩn, kể cả chưa duyệt).
     * Query: ?is_hidden=0/1&per_page=20
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = Review::query()->with(['user', 'menuItem', 'photos']);
        if ($request->has('is_hidden')) {
            $query->where('is_hidden', $request->boolean('is_hidden'));
        }
        if ($request->has('is_approved')) {
            $query->where('is_approved', $request->boolean('is_approved'));
        }
        $perPage = max(1, min((int) $request->query('per_page', 20), 50));
        $page = $query->orderByDesc('id')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => [
                'items' => $page->getCollection()->map(fn (Review $r) => $this->reviewService->format($r, true))->values()->all(),
                'meta'  => [
                    'current_page' => $page->currentPage(),
                    'last_page'    => $page->lastPage(),
                    'per_page'     => $page->perPage(),
                    'total'        => $page->total(),
                ],
            ],
        ]);
    }

    /**
     * PATCH /api/reviews/{id}/hide  (MANAGER)
     * Body: { is_hidden: bool }
     */
    public function hide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'is_hidden' => ['required', 'boolean'],
        ]);
        $review = Review::findOrFail($id);
        $review->forceFill([
            'is_hidden'   => $data['is_hidden'],
            'approved_by' => $request->user()->id,
            'approved_at' => $review->approved_at ?? now(),
        ])->save();

        return response()->json([
            'success' => true,
            'message' => $data['is_hidden'] ? 'Đã ẩn đánh giá.' : 'Đã hiển thị lại đánh giá.',
            'data'    => $this->reviewService->format($review->fresh(['photos', 'user']), true),
        ]);
    }
}