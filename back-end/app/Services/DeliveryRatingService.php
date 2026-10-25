<?php

namespace App\Services;

use App\Models\{Order, OrderDeliveryPhoto, User};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * DeliveryRatingService — đánh giá dịch vụ giao hàng của khách.
 *
 * Quy tắc nghiệp vụ (BR-DELIVERY-RATING-*):
 *  - BR-DELIVERY-RATING-01: chỉ đơn ONLINE đã COMPLETED mới được đánh giá.
 *  - BR-DELIVERY-RATING-02: 1 khách + 1 đơn chỉ đánh giá 1 lần; đánh giá lại thì ghi đè.
 *  - BR-DELIVERY-RATING-03: rating ∈ [1, 5], comment ≤ 500 ký tự.
 *  - BR-DELIVERY-RATING-04: ảnh JPG/PNG/WEBP, ≤ 4MB, tối đa 5 ảnh / đơn.
 *  - BR-DELIVERY-RATING-05: chỉ chủ đơn được đánh giá đơn của mình.
 */
class DeliveryRatingService
{
    /** Rating hợp lệ. */
    public const RATING_MIN = 1;
    public const RATING_MAX = 5;

    /** Số ảnh tối đa / đánh giá giao hàng. */
    public const MAX_PHOTOS = 5;

    /** Kích thước ảnh tối đa (KB). */
    public const MAX_PHOTO_KB = 4096;

    /** MIME cho phép. */
    public const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    /** Độ dài comment tối đa. */
    public const COMMENT_MAX = 500;

    /**
     * Chấm điểm dịch vụ giao cho 1 đơn.
     *
     * @param array $data { rating:int, comment?:string|null }
     * @param array<int, UploadedFile> $photos
     */
    public function submit(User $user, Order $order, array $data, array $photos = []): Order
    {
        // BR-DELIVERY-RATING-05
        abort_unless(
            (int) $order->customer_id === (int) $user->id,
            403,
            'Bạn không thể đánh giá đơn hàng của người khác.',
        );

        // BR-DELIVERY-RATING-01
        if ($order->order_type !== 'ONLINE') {
            throw ValidationException::withMessages([
                'order_id' => 'Chỉ đơn giao hàng (ONLINE) mới được đánh giá dịch vụ giao.',
            ]);
        }
        if ($order->status !== 'COMPLETED') {
            throw ValidationException::withMessages([
                'order_id' => 'Bạn chỉ có thể đánh giá sau khi đơn hàng đã hoàn thành.',
            ]);
        }

        $rating = (int) $data['rating'];
        if ($rating < self::RATING_MIN || $rating > self::RATING_MAX) {
            throw ValidationException::withMessages([
                'rating' => 'Điểm đánh giá phải từ '.self::RATING_MIN.' đến '.self::RATING_MAX.'.',
            ]);
        }

        $comment = isset($data['comment']) ? trim((string) $data['comment']) : null;
        if ($comment !== null && mb_strlen($comment) > self::COMMENT_MAX) {
            throw ValidationException::withMessages([
                'comment' => 'Nhận xét tối đa '.self::COMMENT_MAX.' ký tự.',
            ]);
        }

        // BR-DELIVERY-RATING-04
        if (count($photos) > self::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'photos' => 'Tối đa '.self::MAX_PHOTOS.' ảnh cho mỗi đánh giá.',
            ]);
        }

        return DB::transaction(function () use ($order, $rating, $comment, $photos) {
            $order->forceFill([
                'delivery_rating'   => $rating,
                'delivery_comment'  => $comment !== '' ? $comment : null,
                'delivery_rated_at' => now(),
            ])->save();

            // Đánh giá lại → thay toàn bộ ảnh cũ.
            $this->deletePhotos($order);

            foreach ($photos as $i => $file) {
                $photo = $this->storePhoto($file);
                OrderDeliveryPhoto::create([
                    'order_id'   => $order->id,
                    'path'       => $photo['path'],
                    'url'        => $photo['url'],
                    'mime'       => $photo['mime'],
                    'size_kb'    => $photo['size_kb'],
                    'sort_order' => $i,
                ]);
            }

            return $order->fresh(['deliveryPhotos']);
        });
    }

    /**
     * Thống kê tổng quan đánh giá dịch vụ giao (dùng cho Manager).
     *
     * @return array{
     *   count:int, average:float, breakdown:array<int,int>,
     *   shipper_stats:array<int,array{shipper_id:int,shipper_name:string|null,count:int,average:float}>,
     *   recent:array<int,array>
     * }
     */
    public function summary(?int $shipperId = null, int $limit = 20): array
    {
        $base = DB::table('orders')
            ->whereNotNull('delivery_rating')
            ->where('order_type', 'ONLINE');

        if ($shipperId !== null) {
            $base->where('shipper_id', $shipperId);
        }

        $rows = (clone $base)
            ->selectRaw('delivery_rating as rating, COUNT(*) as c')
            ->groupBy('delivery_rating')
            ->pluck('c', 'rating')
            ->all();

        $count = (int) array_sum($rows);
        $breakdown = [];
        for ($i = self::RATING_MIN; $i <= self::RATING_MAX; $i++) {
            $breakdown[$i] = (int) ($rows[$i] ?? 0);
        }
        $average = $count > 0
            ? round(array_sum(array_map(
                fn ($r, $c) => (int) $r * (int) $c,
                array_keys($rows),
                array_values($rows),
            )) / $count, 2)
            : 0.0;

        // Thống kê theo từng shipper.
        $perShipper = (clone $base)
            ->selectRaw('shipper_id, COUNT(*) as c, AVG(delivery_rating) as avg_rating')
            ->groupBy('shipper_id')
            ->orderByDesc('c')
            ->get()
            ->map(fn ($row) => [
                'shipper_id'   => $row->shipper_id !== null ? (int) $row->shipper_id : null,
                'shipper_name' => $row->shipper_id !== null
                    ? User::whereKey($row->shipper_id)->value('name')
                    : null,
                'count'         => (int) $row->c,
                'average'       => round((float) $row->avg_rating, 2),
            ])
            ->values()
            ->all();

        // Đánh giá mới nhất (kèm tên shipper + mã đơn) cho Manager xem.
        $recent = (clone $base)
            ->orderByDesc('delivery_rated_at')
            ->limit($limit)
            ->get(['id', 'code', 'shipper_id', 'delivery_rating', 'delivery_comment', 'delivery_rated_at'])
            ->map(fn ($row) => [
                'order_id'    => (int) $row->id,
                'order_code'  => $row->code,
                'shipper_id'  => $row->shipper_id !== null ? (int) $row->shipper_id : null,
                'shipper_name'=> $row->shipper_id !== null
                    ? User::whereKey($row->shipper_id)->value('name')
                    : null,
                'rating'      => (int) $row->delivery_rating,
                'comment'     => $row->delivery_comment,
                'rated_at'    => $row->delivery_rated_at,
            ])
            ->values()
            ->all();

        return [
            'count'         => $count,
            'average'       => $average,
            'breakdown'     => $breakdown,
            'shipper_stats' => $perShipper,
            'recent'        => $recent,
        ];
    }

    /**
     * Format phần đánh giá giao hàng cho FE (chi tiết đơn).
     */
    public function formatForOrder(Order $order): array
    {
        return [
            'rating'    => $order->delivery_rating !== null ? (int) $order->delivery_rating : null,
            'comment'   => $order->delivery_comment,
            'rated_at'  => $order->delivery_rated_at?->toIso8601String(),
            'photos'    => $order->relationLoaded('deliveryPhotos')
                ? $order->deliveryPhotos->map(fn (OrderDeliveryPhoto $p) => [
                    'id'  => (int) $p->id,
                    'url' => $p->url,
                ])->values()->all()
                : [],
        ];
    }

    /* ---------------------------------------------------------- */
    /* Private                                                      */
    /* ---------------------------------------------------------- */

    /**
     * Lưu 1 file ảnh vào disk public/delivery-photos/<timestamp>-<random>.<ext>
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

        $disk = Storage::disk('public');
        $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $filename = sprintf('%d-%s.%s', time(), bin2hex(random_bytes(4)), $extension);
        $relativePath = 'delivery-photos/'.$filename;

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

    private function deletePhotos(Order $order): void
    {
        $photos = OrderDeliveryPhoto::where('order_id', $order->id)->get();
        $disk = Storage::disk('public');
        foreach ($photos as $p) {
            if ($p->path && $disk->exists($p->path)) {
                $disk->delete($p->path);
            }
        }
        OrderDeliveryPhoto::where('order_id', $order->id)->delete();
    }
}
