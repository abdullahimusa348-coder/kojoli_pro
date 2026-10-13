<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only consistency check for every wallet:
 * - cached balance = sum of credits − sum of debits in the ledger;
 * - cached balance = balance_after of the latest entry (0 when there are none);
 * - every entry's balance_after = the running balance at that entry.
 * Mismatches are reported and the command fails; nothing is ever repaired.
 */
class VerifyWalletsCommand extends Command
{
    protected $signature = 'wallet:verify';

    protected $description = 'Check every wallet balance against its ledger (reports only, never repairs)';

    public function handle(): int
    {
        $checked = 0;
        $problems = [];

        Wallet::query()->with('user:id,email')->orderBy('id')->chunkById(200, function ($wallets) use (&$checked, &$problems) {
            foreach ($wallets as $wallet) {
                $checked++;
                $label = "Wallet #{$wallet->id} ({$wallet->user?->email}, {$wallet->type->value})";
                $running = 0;
                $latest = null;
                $chain = null;

                foreach (DB::table('wallet_ledger_entries')->where('wallet_id', $wallet->id)->orderBy('id')->cursor() as $entry) {
                    $running += $entry->direction === 'credit' ? (int) $entry->amount_kobo : -(int) $entry->amount_kobo;
                    if ($chain === null && (int) $entry->balance_after_kobo !== $running) {
                        $chain = "{$label}: entry {$entry->reference} records balance after ".Money::format((int) $entry->balance_after_kobo).' but the ledger adds up to '.Money::format($running).' at that point.';
                    }
                    $latest = (int) $entry->balance_after_kobo;
                }

                if ($running !== $wallet->balance_kobo) {
                    $problems[] = "{$label}: cached balance ".Money::format($wallet->balance_kobo).' but ledger credits − debits = '.Money::format($running).'.';
                }
                if (($latest ?? 0) !== $wallet->balance_kobo) {
                    $problems[] = "{$label}: cached balance ".Money::format($wallet->balance_kobo).' but the latest entry records '.Money::format($latest ?? 0).'.';
                }
                if ($chain !== null) {
                    $problems[] = $chain;
                }
            }
        });

        if ($problems === []) {
            $this->info("All {$checked} wallet(s) are consistent with their ledgers.");

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }
        $this->error(count($problems)." mismatch(es) found in {$checked} wallet(s). Nothing was changed; investigate before any correction.");

        return self::FAILURE;
    }
}
