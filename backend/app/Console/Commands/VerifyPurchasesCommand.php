<?php

namespace App\Console\Commands;

use App\Models\Purchase;
use App\Models\Transaction;
use App\Support\Money;
use App\Support\Purchases\PurchaseAttemptStatus;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Wallet\Direction;
use App\Support\Wallet\TransactionStatus;
use App\Support\Wallet\TransactionType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Checks every purchase against its debit, its refund and its attempts, and
 * the purchase money totals, like wallet:verify does for wallets. Reports
 * only: it never changes, re-checks, refunds or settles anything, so it is
 * safe to run at any time and as often as needed. Output names references
 * only, never phone numbers or customers.
 */
class VerifyPurchasesCommand extends Command
{
    protected $signature = 'purchases:verify';

    protected $description = 'Check every purchase against its debit, refund and attempts, and the purchase totals (reports only, never repairs)';

    public function handle(): int
    {
        // One consistent snapshot: purchases and money keep moving while this runs.
        [$checked, $problems] = DB::transaction(fn () => $this->verify());

        if ($problems === []) {
            $this->info("All {$checked} purchase(s) are consistent with their debits, refunds and attempts, and the purchase totals match.");

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }
        $this->error(count($problems)." problem(s) found in {$checked} purchase(s). Nothing was changed; investigate before any correction.");

        return self::FAILURE;
    }

    /** @return array{0: int, 1: list<string>} */
    private function verify(): array
    {
        $checked = 0;
        $problems = [];

        Purchase::query()
            ->select(['id', 'reference', 'user_id', 'wallet_id', 'amount_kobo', 'status', 'debit_transaction_id', 'refund_transaction_id',
                'successful_attempt_id', 'completed_at'])
            ->with(['debitTransaction', 'refundTransaction', 'successfulAttempt:id,purchase_id,status'])
            ->withCount(['attempts as succeeded_attempts' => fn ($q) => $q->where('status', PurchaseAttemptStatus::Succeeded->value)])
            ->chunkById(200, function ($purchases) use (&$checked, &$problems) {
                foreach ($purchases as $purchase) {
                    $checked++;
                    array_push($problems, ...$this->problemsOf($purchase));
                }
            });

        return [$checked, [...$problems, ...$this->ledgerProblems()]];
    }

    /** @return list<string> */
    private function problemsOf(Purchase $purchase): array
    {
        $label = "Purchase {$purchase->reference} ({$purchase->status->value})";
        $problems = [];

        $debit = $purchase->debitTransaction;
        if ($debit === null) {
            $problems[] = "{$label}: has no debit transaction.";
        } else {
            array_push($problems, ...$this->transactionProblems($label, $purchase, $debit, 'debit', Direction::Debit, 'purchase:'));
        }

        $refund = $purchase->refundTransaction;
        if ($purchase->status === PurchaseStatus::Failed) {
            if ($refund === null) {
                $problems[] = "{$label}: failed but has no refund transaction.";
            } else {
                array_push($problems, ...$this->transactionProblems($label, $purchase, $refund, 'refund', Direction::Credit, 'purchase-refund:'));
            }
        } elseif ($refund !== null) {
            $problems[] = "{$label}: has a refund ({$refund->reference}) but only a failed purchase is refunded.";
        }

        $succeeded = (int) $purchase->succeeded_attempts;
        if ($purchase->status === PurchaseStatus::Successful) {
            if ($purchase->successful_attempt_id === null) {
                $problems[] = "{$label}: has no delivering attempt.";
            } elseif ($purchase->successfulAttempt?->status !== PurchaseAttemptStatus::Succeeded) {
                $problems[] = "{$label}: its delivering attempt has not succeeded.";
            }
            if ($succeeded !== 1) {
                $problems[] = "{$label}: has {$succeeded} succeeded attempts; exactly one is expected.";
            }
        } elseif ($purchase->successful_attempt_id !== null || $succeeded > 0) {
            $problems[] = "{$label}: an attempt succeeded but the purchase is not successful.";
        }

        if ($purchase->isFinal() && $purchase->completed_at === null) {
            $problems[] = "{$label}: has no completion time.";
        } elseif (! $purchase->isFinal() && $purchase->completed_at !== null) {
            $problems[] = "{$label}: has a completion time but is not final.";
        }

        return $problems;
    }

    /** @return list<string> */
    private function transactionProblems(string $label, Purchase $purchase, Transaction $transaction, string $role, Direction $direction, string $keyPrefix): array
    {
        $problems = [];
        if ($transaction->type !== TransactionType::Purchase || $transaction->direction !== $direction) {
            $problems[] = "{$label}: its {$role} {$transaction->reference} is not a purchase {$direction->value}.";
        }
        if ($transaction->status !== TransactionStatus::Successful) {
            $problems[] = "{$label}: its {$role} {$transaction->reference} is {$transaction->status->value}, not successful.";
        }
        if ($transaction->amount_kobo !== (int) $purchase->amount_kobo) {
            $problems[] = "{$label}: its {$role} {$transaction->reference} is ".Money::format($transaction->amount_kobo)
                .' but the purchase charged '.Money::format((int) $purchase->amount_kobo).'.';
        }
        if ((int) $transaction->wallet_id !== (int) $purchase->wallet_id || (int) $transaction->user_id !== (int) $purchase->user_id) {
            $problems[] = "{$label}: its {$role} {$transaction->reference} belongs to another wallet or customer.";
        }
        if ($transaction->idempotency_key !== $keyPrefix.$purchase->reference) {
            $problems[] = "{$label}: its {$role} {$transaction->reference} was not posted for this purchase.";
        }

        return $problems;
    }

    /**
     * Purchase transactions that belong to no purchase, transactions used as
     * both a debit and a refund, and the money totals.
     *
     * @return list<string>
     */
    private function ledgerProblems(): array
    {
        $problems = [];

        $orphans = Transaction::query()->where('type', TransactionType::Purchase->value)
            ->whereNotExists(fn ($q) => $q->from('purchases')->whereColumn('purchases.debit_transaction_id', 'transactions.id'))
            ->whereNotExists(fn ($q) => $q->from('purchases')->whereColumn('purchases.refund_transaction_id', 'transactions.id'))
            ->orderBy('id')->pluck('reference');
        foreach ($orphans as $reference) {
            $problems[] = "Transaction {$reference}: a purchase transaction that belongs to no purchase.";
        }

        $shared = DB::table('purchases as debits')->join('purchases as refunds', 'refunds.refund_transaction_id', '=', 'debits.debit_transaction_id')
            ->join('transactions', 'transactions.id', '=', 'debits.debit_transaction_id')->orderBy('transactions.id')->pluck('transactions.reference');
        foreach ($shared as $reference) {
            $problems[] = "Transaction {$reference}: used as both a purchase debit and a purchase refund.";
        }

        $sum = fn (Direction $direction) => (int) Transaction::query()->where('type', TransactionType::Purchase->value)
            ->where('direction', $direction->value)->where('status', TransactionStatus::Successful->value)->sum('amount_kobo');
        [$debits, $refunds] = [$sum(Direction::Debit), $sum(Direction::Credit)];
        $kept = (int) Purchase::query()->where('status', '!=', PurchaseStatus::Failed->value)->sum('amount_kobo');
        if ($debits - $refunds !== $kept) {
            $problems[] = 'Totals: purchase debits '.Money::format($debits).' minus refunds '.Money::format($refunds).' = '.Money::format($debits - $refunds)
                .', but purchases that were not refunded add up to '.Money::format($kept).'.';
        }

        return $problems;
    }
}
