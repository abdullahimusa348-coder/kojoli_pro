<?php

namespace App\Models;

use App\Support\Wallet\WalletStatus;
use App\Support\Wallet\WalletType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer wallet (one per customer per type). balance_kobo is a cache of
 * the ledger and changes only through WalletService, under a row lock in the
 * same database transaction as the ledger entry. Available balance equals the
 * current balance (no holds in Phase 8).
 */
class Wallet extends Model
{
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => WalletType::class,
            'status' => WalletStatus::class,
            'balance_kobo' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<WalletLedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(WalletLedgerEntry::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function availableKobo(): int
    {
        return $this->balance_kobo;
    }

    public function isFrozen(): bool
    {
        return $this->status === WalletStatus::Frozen;
    }
}
