<?php

namespace App\Services\Wallet;

use App\Exceptions\Wallet\AlreadyReversed;
use App\Exceptions\Wallet\BalanceLimitExceeded;
use App\Exceptions\Wallet\IdempotencyConflict;
use App\Exceptions\Wallet\InsufficientFunds;
use App\Exceptions\Wallet\InvalidAmount;
use App\Exceptions\Wallet\InvalidTransactionState;
use App\Exceptions\Wallet\WalletFrozen;
use App\Models\SystemUser;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Support\Wallet\Direction;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionStatus;
use App\Support\Wallet\TransactionType;
use App\Support\Wallet\WalletStatus;
use App\Support\Wallet\WalletType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only code that moves wallet money. Every credit, debit and reversal:
 * 1. runs in one database transaction (retried on deadlock);
 * 2. locks the wallet row (SELECT ... FOR UPDATE), so operations on a wallet
 *    happen one at a time;
 * 3. returns the earlier result for a repeated idempotency key (a conflicting
 *    reuse of a key is refused);
 * 4. refuses debits on frozen wallets, overdrafts and balances above the cap;
 * 5. writes the transaction, the immutable ledger entry (with balance_after)
 *    and the cached wallet balance together.
 * Unique indexes (transaction idempotency key, references, reverses_entry_id)
 * are the last safety net. Integer kobo only. Authorization is the caller's
 * job (admin actions check permissions). Operations touching several wallets
 * (future transfers) must lock wallets in ascending id order.
 */
class WalletService
{
    /** Hard ceiling for any wallet balance: ₦1,000,000,000,000 (fits unsigned BIGINT and PHP int). */
    public const MAX_BALANCE_KOBO = 100_000_000_000_000;

    private const ATTEMPTS = 3;

    /** The customer's wallet of this type, created on first use (safe if two requests race). */
    public function walletFor(User $user, WalletType $type = WalletType::Main): Wallet
    {
        $find = fn () => Wallet::where('user_id', $user->id)->where('type', $type->value)->first();
        if ($wallet = $find()) {
            return $wallet;
        }

        try {
            return DB::transaction(function () use ($user, $type) {
                $wallet = (new Wallet)->forceFill([
                    'user_id' => $user->id,
                    'type' => $type,
                    'currency' => 'NGN',
                    'balance_kobo' => 0,
                    'status' => WalletStatus::Active,
                ]);
                $wallet->save();

                return $wallet->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            return $find();
        }
    }

    /** @param  array<string, mixed>  $metadata  non-sensitive context */
    public function credit(Wallet $wallet, int $amountKobo, LedgerEntryType $entryType, TransactionType $type, string $description,
        ?string $idempotencyKey = null, ?SystemUser $actor = null, array $metadata = []): WalletResult
    {
        return $this->post($wallet, Direction::Credit, $amountKobo, $entryType, $type, $description, $idempotencyKey, $actor, $metadata);
    }

    /** @param  array<string, mixed>  $metadata  non-sensitive context */
    public function debit(Wallet $wallet, int $amountKobo, LedgerEntryType $entryType, TransactionType $type, string $description,
        ?string $idempotencyKey = null, ?SystemUser $actor = null, array $metadata = []): WalletResult
    {
        return $this->post($wallet, Direction::Debit, $amountKobo, $entryType, $type, $description, $idempotencyKey, $actor, $metadata);
    }

    /**
     * Undoes a successful transaction with one compensating ledger entry and
     * marks it reversed. Only once: a second attempt is refused, unless it
     * repeats the same idempotency key, which returns the first reversal.
     */
    public function reverse(Transaction $transaction, string $reason, ?SystemUser $actor = null, ?string $idempotencyKey = null): WalletResult
    {
        try {
            return DB::transaction(function () use ($transaction, $reason, $actor, $idempotencyKey) {
                $wallet = Wallet::whereKey($transaction->wallet_id)->lockForUpdate()->firstOrFail();
                $tx = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
                $original = $tx->entries()->whereNull('reverses_entry_id')->firstOrFail();

                if ($tx->status === TransactionStatus::Reversed) {
                    return $this->replayReversal($tx, $original, $idempotencyKey);
                }
                if ($tx->status !== TransactionStatus::Successful) {
                    throw new InvalidTransactionState('Only successful transactions can be reversed.');
                }

                $direction = $original->direction->opposite();
                $balance = $this->nextBalance($wallet, $direction, $original->amount_kobo);
                $entry = $this->writeEntry($wallet, $tx, $direction, $original->amount_kobo, $balance, LedgerEntryType::Reversal,
                    "Reversal of {$tx->reference}", $actor, array_filter(['reason' => $reason, 'idempotency_key' => $idempotencyKey]), $original->id);
                $wallet->forceFill(['balance_kobo' => $balance])->save();
                $tx->forceFill(['status' => TransactionStatus::Reversed])->save();

                return new WalletResult($tx, $entry, false);
            }, self::ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            // Another request reversed it at the same moment (reverses_entry_id is unique).
            $tx = $transaction->fresh();

            return $this->replayReversal($tx, $tx->entries()->whereNull('reverses_entry_id')->firstOrFail(), $idempotencyKey);
        }
    }

    /** Freezes or unfreezes a wallet (frozen: credits allowed, debits blocked). */
    public function setStatus(Wallet $wallet, WalletStatus $status): Wallet
    {
        return DB::transaction(function () use ($wallet, $status) {
            $locked = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['status' => $status])->save();

            return $locked;
        }, self::ATTEMPTS);
    }

    /** @param  array<string, mixed>  $metadata */
    private function post(Wallet $wallet, Direction $direction, int $amount, LedgerEntryType $entryType, TransactionType $type,
        string $description, ?string $key, ?SystemUser $actor, array $metadata): WalletResult
    {
        if ($amount < 1 || $amount > self::MAX_BALANCE_KOBO) {
            throw new InvalidAmount;
        }

        try {
            return DB::transaction(function () use ($wallet, $direction, $amount, $entryType, $type, $description, $key, $actor, $metadata) {
                $locked = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();

                if ($key !== null && ($replay = $this->replay($locked, $key, $direction, $amount, $type)) !== null) {
                    return $replay;
                }

                $balance = $this->nextBalance($locked, $direction, $amount);
                $tx = (new Transaction)->forceFill([
                    'reference' => self::reference('TXN'),
                    'user_id' => $locked->user_id,
                    'wallet_id' => $locked->id,
                    'type' => $type,
                    'direction' => $direction,
                    'amount_kobo' => $amount,
                    'status' => TransactionStatus::Successful,
                    'idempotency_key' => $key,
                    'description' => $description,
                    'created_by' => $actor?->id,
                    'metadata' => $metadata ?: null,
                    'completed_at' => now(),
                ]);
                $tx->save();
                $entry = $this->writeEntry($locked, $tx, $direction, $amount, $balance, $entryType, $description, $actor, []);
                $locked->forceFill(['balance_kobo' => $balance])->save();

                return new WalletResult($tx, $entry, false);
            }, self::ATTEMPTS);
        } catch (UniqueConstraintViolationException $e) {
            // A parallel request with the same key won the race: return its result.
            if ($key !== null && ($replay = $this->replay($wallet, $key, $direction, $amount, $type)) !== null) {
                return $replay;
            }
            throw $e;
        }
    }

    /** Balance after the operation, or an exception when it is not allowed. */
    private function nextBalance(Wallet $wallet, Direction $direction, int $amount): int
    {
        if ($direction === Direction::Debit) {
            if ($wallet->status === WalletStatus::Frozen) {
                throw new WalletFrozen;
            }
            if ($amount > $wallet->balance_kobo) {
                throw new InsufficientFunds;
            }

            return $wallet->balance_kobo - $amount;
        }

        if ($amount > self::MAX_BALANCE_KOBO - $wallet->balance_kobo) {
            throw new BalanceLimitExceeded;
        }

        return $wallet->balance_kobo + $amount;
    }

    /** @param  array<string, mixed>  $metadata */
    private function writeEntry(Wallet $wallet, Transaction $tx, Direction $direction, int $amount, int $balanceAfter, LedgerEntryType $entryType,
        string $description, ?SystemUser $actor, array $metadata, ?int $reverses = null): WalletLedgerEntry
    {
        $entry = (new WalletLedgerEntry)->forceFill([
            'reference' => self::reference('WLE'),
            'wallet_id' => $wallet->id,
            'transaction_id' => $tx->id,
            'direction' => $direction,
            'amount_kobo' => $amount,
            'balance_after_kobo' => $balanceAfter,
            'entry_type' => $entryType,
            'reverses_entry_id' => $reverses,
            'description' => $description,
            'created_by' => $actor?->id,
            'metadata' => $metadata ?: null,
        ]);
        $entry->save();

        return $entry;
    }

    /** Earlier result for this key, null when the key is new; refuses a key reused for something else. */
    private function replay(Wallet $wallet, string $key, Direction $direction, int $amount, TransactionType $type): ?WalletResult
    {
        $tx = Transaction::where('user_id', $wallet->user_id)->where('idempotency_key', $key)->first();
        if ($tx === null) {
            return null;
        }
        if ($tx->wallet_id !== $wallet->id || $tx->direction !== $direction || $tx->amount_kobo !== $amount || $tx->type !== $type) {
            throw new IdempotencyConflict;
        }

        return new WalletResult($tx, $tx->entries()->whereNull('reverses_entry_id')->firstOrFail(), true);
    }

    private function replayReversal(Transaction $tx, WalletLedgerEntry $original, ?string $key): WalletResult
    {
        $reversal = WalletLedgerEntry::where('reverses_entry_id', $original->id)->first();
        if ($reversal !== null && $key !== null && ($reversal->metadata['idempotency_key'] ?? null) === $key) {
            return new WalletResult($tx, $reversal, true);
        }

        throw new AlreadyReversed;
    }

    /** Unique, sortable, non-guessable reference such as TXN-01J9Z3M4K5... (no database ids). */
    public static function reference(string $prefix): string
    {
        return $prefix.'-'.strtoupper((string) Str::ulid());
    }
}
