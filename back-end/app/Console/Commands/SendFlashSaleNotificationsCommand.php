<?php

namespace App\Console\Commands;

use App\Services\FlashSaleNotificationService;
use Illuminate\Console\Command;

class SendFlashSaleNotificationsCommand extends Command
{
    protected $signature = 'flash-sale:notify';

    protected $description = 'Thông báo cho khách khi Flash Sale bắt đầu';

    public function handle(FlashSaleNotificationService $notifications): int
    {
        $this->info('Đã gửi '.$notifications->sendDue().' thông báo Flash Sale.');

        return self::SUCCESS;
    }
}
