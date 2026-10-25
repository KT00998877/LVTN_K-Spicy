<?php

namespace App\Services;

use App\Events\CustomerPromoNotificationCreated;
use App\Models\MarketingCampaign;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MarketingCampaignService — gửi ưu đãi theo khung giờ vàng tới khách thân thiết.
 *
 * Quy tắc BR-MKT-*:
 *  - BR-MKT-01: chỉ campaign SCHEDULED mới được gửi; SENT/SKIPPED/CANCELLED là kết thúc.
 *  - BR-MKT-02: ưu đãi có mã voucher bắt buộc.
 *  - BR-MKT-03: chỉ gửi khi hiện tại nằm trong [window_start, window_end].
 *  - BR-MKT-04: mỗi khách tối đa 1 ưu đãi/ngày (chống spam).
 *  - BR-MKT-05: chỉ nhắm khách đã từng chi (khách thân thiết), trừ khi campaign
 *    nhắm tất cả (target_tier_ids rỗng) thì vẫn giới hạn khách đã đăng ký.
 */
class MarketingCampaignService
{
    /** Số khách tối đa nhận 1 ưu đãi trong 1 ngày. */
    public const DAILY_LIMIT = 1;

    /** Loại notification lưu trong bảng `notifications`. */
    public const NOTIFICATION_TYPE = 'customer.promo';

    /**
     * Tìm các campaign đến hạn gửi (đã tới send_at và đang trong khung giờ vàng).
     *
     * @return \Illuminate\Support\Collection<int, MarketingCampaign>
     */
    public function dueCampaigns()
    {
        return MarketingCampaign::scheduled()
            ->where('send_at', '<=', now())
            ->get()
            ->filter(fn (MarketingCampaign $c) => $c->isWithinWindow());
    }

    /**
     * Gửi 1 campaign cho nhóm khách mục tiêu.
     *
     * @return int số khách thực sự nhận được
     */
    public function send(MarketingCampaign $campaign): int
    {
        abort_if(! $campaign->isScheduled(), 422, 'Chiến dịch không ở trạng thái chờ gửi.');
        abort_if($campaign->voucher_code === null || $campaign->voucher_code === '', 422, 'Chiến dịch chưa có mã voucher.');

        $recipients = $this->recipients($campaign);
        $sent = 0;

        foreach ($recipients as $user) {
            if ($this->notifyUser($campaign, $user)) $sent++;
        }

        $campaign->forceFill([
            'status'        => MarketingCampaign::STATUS_SENT,
            'sent_at'       => now(),
            'recipient_count' => $sent,
        ])->save();

        return $sent;
    }

    /**
     * Gửi 1 campaign cho nhóm khách mục tiêu.
     *
     * @return int số khách thực sự nhận được
     */
    public function recipients(MarketingCampaign $campaign)
    {
        $tierIds = $campaign->target_tier_ids ?: [];

        $query = User::query()
            ->where('is_active', true)
            ->whereNotNull('email');

        if ($tierIds !== []) {
            // Nhắm hạng cụ thể: chỉ khách thuộc các hạng đó.
            $query->whereIn('loyalty_tier_id', $tierIds);
        }

        // Khách thân thiết = đã từng chi. Ngoại lệ: campaign nhắm đúng hạng thì
        // vẫn giữ điều kiện này vì khách mới chưa có hạng thuộc đúng danh sách.
        if ($tierIds === []) {
            $query->where('total_spent', '>', 0);
        }

        return $query->orderByDesc('total_spent')->get();
    }

    /**
     * Ghi 1 notification cho khách. Trả về false nếu khách đã nhận ưu đãi hôm nay.
     */
    public function notifyUser(MarketingCampaign $campaign, User $user): bool
    {
        // BR-MKT-04: đã nhận ưu đãi khác trong 24h qua thì bỏ qua.
        $alreadyRecently = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('type', self::NOTIFICATION_TYPE)
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($alreadyRecently) return false;

        $id = (string) Str::uuid();
        $data = [
            'event'        => 'PROMO_CAMPAIGN',
            'campaign_id'  => $campaign->id,
            'name'         => $campaign->name,
            'message'      => $campaign->message,
            'voucher_code' => $campaign->voucher_code,
            'banner_image_url' => $campaign->banner_image_url,
            'accent_color' => $campaign->accent_color,
            'cta_text'     => $campaign->cta_text,
            'landing_url'  => $campaign->landing_url,
        ];

        $createdAt = now();
        $inserted = DB::table('notifications')->insertOrIgnore([
            'id' => $id,
            'type' => self::NOTIFICATION_TYPE,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'deduplication_key' => 'promo:'.$campaign->id.':'.$user->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        if (! $inserted) return false;

        CustomerPromoNotificationCreated::dispatch((int) $user->id, [
            'id' => $id,
            'data' => $data,
            'read_at' => null,
            'created_at' => $createdAt->toIso8601String(),
        ]);

        return true;
    }
}
