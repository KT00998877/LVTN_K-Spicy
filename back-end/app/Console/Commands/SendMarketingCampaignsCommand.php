<?php

namespace App\Console\Commands;

use App\Jobs\SendMarketingCampaignJob;
use App\Models\MarketingCampaign;
use Illuminate\Console\Command;

/**
 * Command `marketing:send-campaigns` — quét các chiến dịch ưu đãi đến hạn
 * và dispatch job gửi cho từng chiến dịch.
 *
 * Đăng ký trong routes/console.php:
 *   Schedule::command('marketing:send-campaigns')->everyTenMinutes()->withoutOverlapping();
 */
class SendMarketingCampaignsCommand extends Command
{
    protected $signature = 'marketing:send-campaigns';

    protected $description = 'Gửi ưu đãi cho khách thân thiết theo khung giờ vàng đã cấu hình';

    public function handle(): int
    {
        $campaigns = MarketingCampaign::scheduled()
            ->where('send_at', '<=', now())
            ->get()
            ->filter(fn (MarketingCampaign $c) => $c->isWithinWindow());

        if ($campaigns->isEmpty()) {
            $this->info('Không có chiến dịch ưu đãi nào đến hạn gửi.');

            return self::SUCCESS;
        }

        foreach ($campaigns as $campaign) {
            SendMarketingCampaignJob::dispatch($campaign->id);
        }

        $this->info('Đã dispatch '.count($campaigns).' chiến dịch ưu đãi.');

        return self::SUCCESS;
    }
}
