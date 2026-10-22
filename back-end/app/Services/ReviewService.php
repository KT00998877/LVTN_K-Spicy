<?php

namespace App\Services;

use App\Models\{MenuItem, Order, OrderItem, Review, ReviewPhoto, User};
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ReviewService — xử lý logic review món ăn.
 *
 * Quy tắc (BR-REVIEW-*):
 *  - BR-REVIEW-01: chỉ khách đã mua món (order COMPLETED + có order_item.menu_item_id) mới review được.
 *  - BR-REVIEW-02: 1 user + 1 menu_item chỉ review 1 lần (unique index). Có thể update sau.
 *  - BR-REVIEW-03: rating ∈ [1, 5], comment ≤ 1000 ký tự.
 *  - BR-REVIEW-04: ảnh JPG/PNG/WEBP, ≤ 4MB, tối đa 5 ảnh / review.
 *  - BR-REVIEW-05: review auto-approved, manager có thể ẩn qua `is_hidden`.
 */
class ReviewService
{
    /** Rating hợp lệ. */
    public const RATING_MIN = 1;
    public const RATING_MAX = 5;

    /** Số ảnh tối đa / review. */
    public const MAX_PHOTOS = 5;

    /** Kích thước ảnh tối đa (KB). */
    public const MAX_PHOTO_KB = 4096;

    /** MIME cho phép. */
    public const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    /** Độ dài comment tối đa. */
    public const COMMENT_MAX = 1000;

    /**
     * Kiểm tra khách có đủ điều kiện review món không.
     * Trả về Order hợp lệ (COMPLETED) chứa menu_item_id này.
     *
     * @throws ValidationException
     */
    public function findEligibleOrder(User $user, MenuItem $menuItem): ?Order
    {
        $order = Order::query()
            ->where('customer_id', $user->id)
            ->where('status', 'COMPLETED')
            ->whereHas('items', fn ($q) => $q->where('menu_item_id', $menuItem->id))
            ->orderByDesc('completed_at')
            ->first();

        return $order;
    }

    /**
     * Tạo review mới.
     *
     * @param array $data { rating:int, comment?:string|null, menu_item_id:int }
     * @param array<int, UploadedFile> $photos
     */
    public function create(User $user, array $data, array $photos = []): Review
    {
        $menuItem = MenuItem::findOrFail($data['menu_item_id']);
        $rating = (int) $data['rating'];
        $comment = isset($data['comment']) ? trim((string) $data['comment']) : null;

        // BR-REVIEW-03
        if ($rating < self::RATING_MIN || $rating > self::RATING_MAX) {
            throw ValidationException::withMessages([
                'rating' => 'Điểm đánh giá phải từ '.self::RATING_MIN.' đến '.self::RATING_MAX.'.',
            ]);
        }
        if ($comment !== null && mb_strlen($comment) > self::COMMENT_MAX) {
            throw ValidationException::withMessages([
                'comment' => 'Nhận xét tối đa '.self::COMMENT_MAX.' ký tự.',
            ]);
        }

        // BR-REVIEW-01
        $order = $this->findEligibleOrder($user, $menuItem);
        if (!$order) {
            throw ValidationException::withMessages([
                'menu_item_id' => 'Bạn cần hoàn thành đơn có món này trước khi đánh giá.',
            ]);
        }

        // BR-REVIEW-02
        $exists = Review::where('user_id', $user->id)
            ->where('menu_item_id', $menuItem->id)
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages([
                'menu_item_id' => 'Bạn đã đánh giá món này rồi. Có thể chỉnh sửa đánh giá của bạn.',
            ]);
        }

        // BR-REVIEW-04: ảnh tối đa 5
        if (count($photos) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'photos' => 'Tối đa '.self::MAX_PHOTOS.' ảnh cho mỗi đánh giá.',
            ]);
        }

        return DB::transaction(function () use ($user, $menuItem, $order, $rating, $comment, $photos) {
            $review = Review::create([
                'user_id'       => $user->id,
                'menu_item_id'  => $menuItem->id,
                'order_id'      => $order->id,
                'rating'        => $rating,
                'comment'       => $comment !== '' ? $comment : null,
                'is_approved'   => true,
                'approved_at'   => now(),
            ]);

            foreach ($photos as $i => $file) {
                $photo = $this->storePhoto($file);
                ReviewPhoto::create([
                    'review_id'  => $review->id,
                    'path'       => $photo['path'],
                    'url'        => $photo['url'],
                    'mime'       => $photo['mime'],
                    'size_kb'    => $photo['size_kb'],
                    'sort_order' => $i,
                ]);
            }

            return $review->fresh(['photos', 'user']);
        });
    }

    /**
     * Khách cập nhật review của mình.
     *
     * @param array $data { rating?:int, comment?:string|null }
     * @param array<int, UploadedFile>|null $photos (null = không đổi ảnh)
     */
    public function update(User $user, Review $review, array $data, ?array $photos = null): Review
    {
        abort_unless($review->user_id === $user->id, 403, 'Bạn không thể sửa đánh giá của người khác.');

        if (isset($data['rating'])) {
            $rating = (int) $data['rating'];
            if ($rating < self::RATING_MIN || $rating > self::RATING_MAX) {
                throw ValidationException::withMessages([
                    'rating' => 'Điểm đánh giá phải từ '.self::RATING_MIN.' đến '.self::RATING_MAX.'.',
                ]);
            }
            $review->rating = $rating;
        }
        if (array_key_exists('comment', $data)) {
            $comment = trim((string) ($data['comment'] ?? ''));
            if ($comment !== '' && mb_strlen($comment) > self::COMMENT_MAX) {
                throw ValidationException::withMessages([
                    'comment' => 'Nhận xét tối đa '.self::COMMENT_MAX.' ký tự.',
                ]);
            }
            $review->comment = $comment !== '' ? $comment : null;
        }

        DB::transaction(function () use ($review, $photos) {
            $review->save();

            // Thay ảnh nếu có upload mới.
            if ($photos !== null) {
                if (count($photos) > self::MAX_PHOTOS) {
                    throw ValidationException::withMessages([
                        'photos' => 'Tối đa '.self::MAX_PHOTOS.' ảnh cho mỗi đánh giá.',
                    ]);
                }
                // Xóa file cũ + row cũ.
                $this->deletePhotos($review);
                foreach ($photos as $i => $file) {
                    $photo = $this->storePhoto($file);
                    ReviewPhoto::create([
                        'review_id'  => $review->id,
                        'path'       => $photo['path'],
                        'url'        => $photo['url'],
                        'mime'       => $photo['mime'],
                        'size_kb'    => $photo['size_kb'],
                        'sort_order' => $i,
                    ]);
                }
            }
        });

        return $review->fresh(['photos', 'user']);
    }

    /** Khách xóa review của mình (cascade photos + files). */
    public function delete(User $user, Review $review): void
    {
        abort_unless($review->user_id === $user->id, 403, 'Bạn không thể xóa đánh giá của người khác.');
        DB::transaction(function () use ($review) {
            $this->deletePhotos($review);
            $review->delete();
        });
    }

    /**
     * Tổng hợp rating cho 1 món (count, average, breakdown).
     *
     * @return array{count:int, average:float, breakdown:array<int,int>}
     */
    public function summaryForMenuItem(int $menuItemId): array
    {
        $rows = DB::table('reviews')
            ->where('menu_item_id', $menuItemId)
            ->where('is_approved', true)
            ->where('is_hidden', false)
            ->selectRaw('rating, COUNT(*) as c')
            ->groupBy('rating')
            ->pluck('c', 'rating')
            ->all();

        $count = array_sum($rows);
        $breakdown = [];
        for ($i = self::RATING_MIN; $i <= self::RATING_MAX; $i++) {
            $breakdown[$i] = (int) ($rows[$i] ?? 0);
        }
        $average = $count > 0
            ? round(array_sum(array_map(fn ($r, $c) => $r * $c, array_keys($rows), $rows)) / $count, 2)
            : 0.0;

        return [
            'count'     => $count,
            'average'   => $average,
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Lấy reviews của 1 món (approved + không ẩn), kèm photos + author.
     */
    public function listForMenuItem(int $menuItemId, int $perPage = 10, ?int $userId = null): array
    {
        $query = Review::query()
            ->with(['photos', 'user'])
            ->where('menu_item_id', $menuItemId)
            ->where('is_approved', true)
            ->where('is_hidden', false)
            ->orderByDesc('id');

        // Nếu user đăng nhập → đưa review của họ lên đầu.
        if ($userId !== null) {
            $query->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$userId]);
        }

        $page = $query->paginate(max(1, min($perPage, 50)));

        return [
            'items' => $page->getCollection()->map(fn (Review $r) => $this->format($r))->values()->all(),
            'meta'  => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ];
    }

    /**
     * Lấy review của 1 user (AccountView dùng).
     */
    public function listForUser(User $user, int $perPage = 20): array
    {
        $page = Review::query()
            ->with(['photos', 'menuItem'])
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate(max(1, min($perPage, 50)));

        return [
            'items' => $page->getCollection()->map(fn (Review $r) => $this->format($r, true))->values()->all(),
            'meta'  => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
            ],
        ];
    }

    /** Format 1 review cho FE. */
    public function format(Review $r, bool $includeMenuItem = false): array
    {
        return [
            'id'           => (int) $r->id,
            'rating'       => (int) $r->rating,
            'comment'      => $r->comment,
            'is_hidden'    => (bool) $r->is_hidden,
            'created_at'   => $r->created_at?->toIso8601String(),
            'updated_at'   => $r->updated_at?->toIso8601String(),
            'photos'       => $r->photos->map(fn (ReviewPhoto $p) => [
                'id'  => (int) $p->id,
                'url' => $p->url,
            ])->values()->all(),
            'user'         => [
                'id'   => (int) $r->user_id,
                'name' => $r->user?->name,
            ],
            'menu_item_id' => (int) $r->menu_item_id,
            ...($includeMenuItem && $r->menuItem ? [
                'menu_item' => [
                    'id'   => (int) $r->menuItem->id,
                    'name' => $r->menuItem->name,
                    'slug' => $r->menuItem->slug,
                    'image_url' => $r->menuItem->image_url,
                ],
            ] : []),
        ];
    }

    /* ---------------------------------------------------------- */
    /* Private                                                      */
    /* ---------------------------------------------------------- */

    /**
     * Lưu 1 file ảnh vào disk public/review-photos/<user_id>/<timestamp>-<random>.<ext>
     *
     * @return array{path:string,url:string,mime:string,size_kb:int}
     */
    private function storePhoto(UploadedFile $file): array
    {
        if (!in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
            throw ValidationException::withMessages([
                'photos' => 'Ảnh phải có định dạng JPG, PNG hoặc WEBP.',
            ]);
        }
        if ($file->getSize() > self::MAX_PHOTO_KB * 1024) {
            throw ValidationException::withMessages([
                'photos' => 'Ảnh tối đa '.self::MAX_PHOTO_KB.'KB.',
            ]);
        }

        $disk = \Storage::disk('public');
        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $filename = sprintf('%d-%s.%s', time(), bin2hex(random_bytes(4)), $extension);
        $relativePath = 'review-photos/' . $filename;

        $disk->put($relativePath, file_get_contents($file->getRealPath()));
        $url = $disk->url($relativePath);
        $sizeKb = (int) ceil($file->getSize() / 1024);

        return [
            'path'    => $relativePath,
            'url'     => $url,
            'mime'    => $file->getMimeType(),
            'size_kb' => $sizeKb,
        ];
    }

    private function deletePhotos(Review $review): void
    {
        /** @var Collection<int, ReviewPhoto> $photos */
        $photos = $review->photos()->get();
        $disk = \Storage::disk('public');
        foreach ($photos as $p) {
            if ($p->path && $disk->exists($p->path)) {
                $disk->delete($p->path);
            }
        }
        $photos->each->delete();
    }
}