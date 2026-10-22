<?php

namespace App\Console\Commands;

use App\Models\ChatPendingAction;
use Illuminate\Console\Command;

/**
 * Expire pending chat actions past their TTL.
 *
 * Runs on a schedule so the table does not accumulate stale rows. Correctness
 * does not depend on it: confirmPendingAction() also checks expires_at inline
 * and returns 410, so a missed run can never allow a late write.
 */
class ExpirePendingActions extends Command
{
    protected $signature = 'chatbot:expire-pending';

    protected $description = 'Mark expired chatbot pending actions';

    public function handle(): int
    {
        $count = ChatPendingAction::expired()->update([
            'status' => 'expired',
            'error_message' => 'Yêu cầu đã hết hạn.',
        ]);

        if ($count > 0) {
            \Illuminate\Support\Facades\Log::info('chatbot.pending.expire', [
                'expired_count' => $count,
            ]);
            $this->info("Expired {$count} pending action(s).");
        }

        return self::SUCCESS;
    }
}