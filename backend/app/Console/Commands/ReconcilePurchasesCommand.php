<?php

namespace App\Console\Commands;

use App\Services\Purchases\PurchaseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Re-checks pending and review purchases that are due (scheduled every five minutes; no queue worker needed). */
class ReconcilePurchasesCommand extends Command
{
    protected $signature = 'purchases:reconcile';

    protected $description = 'Re-check purchases with an unclear provider outcome and settle them only on a definite result';

    public function handle(PurchaseService $purchases): int
    {
        $stats = $purchases->reconcile();
        $this->info("Checked {$stats['checked']} purchase(s): {$stats['settled']} settled, {$stats['review']} moved to review, {$stats['errors']} error(s).");

        // Scheduled runs leave a trace in the log: counts only, never references, customers or phone numbers.
        if ($stats['checked'] > 0) {
            Log::log($stats['errors'] > 0 ? 'warning' : 'info', 'Purchase re-checks ran', $stats);
        }

        return self::SUCCESS;
    }
}
