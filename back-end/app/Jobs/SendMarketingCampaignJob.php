<?php

namespace App\Jobs;

use App\Models\MarketingCampaign;
use App\Services\MarketingCampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * SendMarketingCampaignJob — gửi 1 chiến dịch ưu đãi tới nhóm khách mục tiêu.
 *
 * Job được dispatch bởi command `marketing:send-campaigns` (chạy theo cron
 * mỗi 10 phút). Mỗi campaign là 1 job riêng để nếu 1 chiến dịch lỗi thì
 * không chặn các chiến dịch còn lại.
 */
class SendMarketingCampaignJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $campaignId)
    {
    }

    public function handle(MarketingCampaignService $service): void
    {
        $campaign = MarketingCampaign::find($this->campaignId);

        // Campaign đã bị xoá / đã gửi / đã huỷ → bỏ qua.
        if (! $campaign || ! $campaign->isScheduled()) return;

        // Chưa tới khung giờ vàng → chờ cron lần sau (không phải SKIPPED).
        if (! $campaign->isWithinWindow()) {
            // Quá hạn (lỡ quên, quay lại sau nhiều ngày) thì đánh dấu bỏ qua.
            if ($campaign->isPastWindow()) {
                $campaign->forceFill(['status' => MarketingCampaign::STATUS_SKIPPED])->save();
            }

            return;
        }

        $service->send($campaign);
    }
}
