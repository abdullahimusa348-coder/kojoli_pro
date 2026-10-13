<?php

namespace App\Services\Wallet;

use App\Models\Transaction;
use App\Models\WalletLedgerEntry;

/** Outcome of a wallet operation. replayed = an earlier result returned for a repeated idempotency key; nothing new was posted. */
final readonly class WalletResult
{
    public function __construct(
        public Transaction $transaction,
        public WalletLedgerEntry $entry,
        public bool $replayed,
    ) {}
}
