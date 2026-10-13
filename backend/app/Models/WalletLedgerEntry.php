<?php

namespace App\Models;

use App\Support\Wallet\Direction;
use App\Support\Wallet\LedgerEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One immutable wallet ledger row. Written only by WalletService; never
 * updated or deleted. Corrections are new "reversal" rows that point back
 * through reverses_entry_id.
 */
class WalletLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Wallet ledger entries are append-only.'));
        static::deleting(fn () => throw new LogicException('Wallet ledger entries are append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => Direction::class,
            'entry_type' => LedgerEntryType::class,
            'amount_kobo' => 'integer',
            'balance_after_kobo' => 'integer',
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<self, $this> */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_entry_id');
    }

    /** @return HasOne<self, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_entry_id');
    }

    /** @return BelongsTo<SystemUser, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'created_by')->withTrashed();
    }

    /** Signed amount: positive for credits, negative for debits. */
    public function signedKobo(): int
    {
        return $this->direction === Direction::Credit ? $this->amount_kobo : -$this->amount_kobo;
    }
}
