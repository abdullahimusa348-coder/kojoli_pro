<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentService;
use Illuminate\Console\Command;

/** Clears stored webhook payloads after the retention period (the outcome rows stay). */
class PrunePaymentWebhooksCommand extends Command
{
    protected $signature = 'payments:prune-webhooks';

    protected $description = 'Remove webhook payloads older than the retention period, keeping the outcome rows';

    public function handle(PaymentService $payments): int
    {
        $this->info('Cleared '.$payments->prunePayloads().' webhook payload(s).');

        return self::SUCCESS;
    }
}
