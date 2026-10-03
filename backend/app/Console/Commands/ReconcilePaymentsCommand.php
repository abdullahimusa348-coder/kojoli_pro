<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentService;
use Illuminate\Console\Command;

/** Rechecks pending payments with their gateway (scheduled every five minutes; no queue worker needed). */
class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Verify pending payments with their gateway and settle them safely';

    public function handle(PaymentService $payments): int
    {
        $stats = $payments->reconcile();
        $this->info("Checked {$stats['checked']} pending payment(s): {$stats['settled']} settled, {$stats['unavailable']} gateway check(s) unavailable.");

        return self::SUCCESS;
    }
}
