<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\Payment\VNPayService;
use Illuminate\Console\Command;

class ReconcileVNPay extends Command
{
    protected $signature = 'vnpay:reconcile {--days=7 : Look back this many days}';
    protected $description = 'Query pending VNPAY payments even when the browser never returns';

    public function handle(VNPayService $service): int
    {
        // VNPay cannot route loopback/private addresses, so a reconcile run with an
        // unconfigured server IP silently fails on every payment. Warn loudly.
        $ip = (string) config('vnpay.server_ip');
        if (!config('vnpay.server_ip') || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $this->warn('VNP_SERVER_IP is unset or private ('.($ip ?: 'empty').'). Set it to the public IP so querydr can reach VNPay.');
        }
        $days = max(1, min(365, (int) $this->option('days')));
        Payment::where('provider', 'vnpay')->where('status', 'PENDING')
            ->where('created_at', '>=', now()->subDays($days))->orderBy('id')
            ->chunkById(100, function ($payments) use ($service) {
                foreach ($payments as $payment) $service->reconcile($payment);
            });
        $this->info('VNPAY reconciliation finished. Unconfirmed payments remain pending.');
        return self::SUCCESS;
    }
}
