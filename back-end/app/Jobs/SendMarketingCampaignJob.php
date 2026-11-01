<?php

namespace App\Jobs;

use App\Models\MarketingCampaign;
use App\Services\MarketingCampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

        try {
            $service->send($campaign, true);
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 422) throw $e;
        }
    }
}
