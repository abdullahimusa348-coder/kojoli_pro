<?php

namespace App\Console\Commands;

use App\Models\Purchase;
use App\Models\Transaction;
use App\Services\Providers\Data\ProviderResultFields;
use App\Support\Money;
use App\Support\Phone\NigerianPhone;
use App\Support\Purchases\PurchaseAttemptStatus;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Purchases\RecipientType;
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
 * only, never phone numbers, NIN/BVN numbers or customers.
 * Recipients (Phase 11): a phone purchase stores a canonical phone and a
 * fingerprint and has no identity recipient; a NIN/BVN purchase stores
 * neither and has its identity recipient, with a masked value, keyed hashes,
 * a consent time and a number that can still be read (checked yes/no only).
 * Results (Phase 11 CP2): a successful NIN/BVN purchase has exactly one
 * result, from its delivering attempt, with a valid field count and fields
 * that can still be read (checked yes/no only, never printed); every other
 * purchase has none.
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
                'successful_attempt_id', 'completed_at', 'recipient_type', 'recipient', 'request_fingerprint'])
            ->with(['debitTransaction', 'refundTransaction', 'successfulAttempt:id,purchase_id,status', 'identityRecipient', 'result'])
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

        return [...$problems, ...$this->recipientProblems($label, $purchase), ...$this->resultProblems($label, $purchase)];
    }

    /**
     * The delivered result: only a successful NIN/BVN purchase has one, from
     * its delivering attempt. Never prints or returns a result value.
     *
     * @return list<string>
     */
    private function resultProblems(string $label, Purchase $purchase): array
    {
        $result = $purchase->result;
        if ($purchase->status !== PurchaseStatus::Successful || ! $purchase->recipient_type->isIdentity()) {
            return $result === null ? [] : ["{$label}: has a stored result, but only a successful NIN or BVN purchase has one."];
        }
        if ($result === null) {
            return ["{$label}: a successful {$purchase->recipient_type->label()} purchase without its result."];
        }

        $problems = [];
        if ($result->purchase_attempt_id !== $purchase->successful_attempt_id) {
            $problems[] = "{$label}: its result is not from its delivering attempt.";
        }
        if ($result->field_count < 1 || $result->field_count > ProviderResultFields::MAX_FIELDS) {
            $problems[] = "{$label}: its result has an invalid field count.";
        } elseif (! $result->canBeRead()) {
            $problems[] = "{$label}: its result cannot be read (app key not in APP_PREVIOUS_KEYS, or a damaged or miscounted value).";
        }

        return $problems;
    }

    /**
     * How the recipient is stored for the purchase's type. Never prints or
     * returns a phone number or a NIN/BVN.
     *
     * @return list<string>
     */
    private function recipientProblems(string $label, Purchase $purchase): array
    {
        $type = $purchase->recipient_type;
        $identity = $purchase->identityRecipient;
        $problems = [];

        if ($type === RecipientType::Phone) {
            if (! NigerianPhone::isCanonical($purchase->recipient)) {
                $problems[] = "{$label}: a phone purchase without a canonical phone recipient.";
            }
            if (! self::isHash($purchase->request_fingerprint)) {
                $problems[] = "{$label}: a phone purchase without a request fingerprint.";
            }
            if ($identity !== null) {
                $problems[] = "{$label}: a phone purchase with an identity recipient.";
            }

            return $problems;
        }

        if ($purchase->recipient !== null || $purchase->request_fingerprint !== null) {
            $problems[] = "{$label}: a {$type->label()} purchase that stores a phone recipient or phone fingerprint.";
        }
        if ($identity === null) {
            $problems[] = "{$label}: a {$type->label()} purchase without its identity recipient.";

            return $problems;
        }
        if (preg_match('/\A•{7}\d{4}\z/u', (string) $identity->masked_value) !== 1) {
            $problems[] = "{$label}: its identity recipient's display value is not masked.";
        }
        if (! self::isHash($identity->lookup_hash) || ! self::isHash($identity->keyed_fingerprint)) {
            $problems[] = "{$label}: its identity recipient's lookup hash or fingerprint is not a keyed hash.";
        }
        if ($identity->consented_at === null) {
            $problems[] = "{$label}: its identity recipient has no consent time.";
        }
        if (! $identity->isReadable($type)) {
            $problems[] = "{$label}: its {$type->label()} cannot be read (app key not in APP_PREVIOUS_KEYS, or a damaged value); nothing can be sent to a provider.";
        }

        return $problems;
    }

    private static function isHash(?string $value): bool
    {
        return $value !== null && preg_match('/\A[0-9a-f]{64}\z/', $value) === 1;
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
