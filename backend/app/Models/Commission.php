<?php

namespace App\Models;

use App\Support\Pricing\BasisPoints;
use App\Support\Purchases\PurchaseStatus;
use App\Support\Referrals\CommissionStatus;
use App\Support\Referrals\QualifyingServices;
use App\Support\Wallet\Direction;
use App\Support\Wallet\LedgerEntryType;
use App\Support\Wallet\TransactionStatus;
use App\Support\Wallet\TransactionType;
use App\Support\Wallet\WalletType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * A referral commission credited to the referrer's Main Wallet for a
 * referred customer's successful purchase of a qualifying service (Phase 12).
 * Written once, in the transaction that marks the purchase successful, with
 * the base amount (the purchase's amount_kobo), the rate and cap in force
 * then, and its wallet credit. Never changed, deleted or recalculated: its
 * status comes from its single staff action, if it has one.
 */
class Commission extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::creating(fn (self $commission) => $commission->enforceInvariants());
        static::updating(fn () => throw new LogicException('Commissions never change; a staff action is a separate record.'));
        static::deleting(fn () => throw new LogicException('Commissions are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purchase_id' => 'integer',
            'referrer_id' => 'integer',
            'wallet_id' => 'integer',
            'credit_transaction_id' => 'integer',
            'base_amount_kobo' => 'integer',
            'rate_bps' => 'integer',
            'cap_kobo' => 'integer',
            'amount_kobo' => 'integer',
            'credited_at' => 'datetime',
        ];
    }

    /**
     * The commission for a purchase amount: the rate as a percentage of the
     * amount, then the cap, rounded down to whole kobo (decisions 6–8).
     * Integer arithmetic only: a purchase is debited from a wallet capped at
     * 10^14 kobo, so the product stays far below PHP_INT_MAX.
     */
    public static function amountFor(int $baseKobo, int $rateBps, int $capKobo): int
    {
        return min(intdiv($baseKobo * $rateBps, 10_000), $capKobo);
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return BelongsTo<User, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** @return BelongsTo<Transaction, $this> */
    public function creditTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'credit_transaction_id');
    }

    /** @return HasOne<CommissionAction, $this> */
    public function action(): HasOne
    {
        return $this->hasOne(CommissionAction::class);
    }

    /** Credited, or what its staff action made it. */
    public function status(): CommissionStatus
    {
        return $this->action?->type->status() ?? CommissionStatus::Credited;
    }

    private function enforceInvariants(): void
    {
        if (! is_string($this->reference) || preg_match('/\ACOM-[0-9A-Z]{26}\z/', $this->reference) !== 1) {
            throw new LogicException('A commission reference is COM- followed by a ULID.');
        }
        $purchase = Purchase::find($this->purchase_id);
        if ($purchase === null || $purchase->status !== PurchaseStatus::Successful) {
            throw new LogicException('A commission belongs to a successful purchase.');
        }
        if (! QualifyingServices::includes(Service::whereKey($purchase->service_id)->value('slug'))) {
            throw new LogicException('Only purchases of the qualifying services earn commission.');
        }
        $referral = Referral::where('referred_user_id', $purchase->user_id)->first();
        if ($referral === null || $referral->referrer_id !== $this->referrer_id || $this->referrer_id === $purchase->user_id) {
            throw new LogicException('A commission goes to the buyer\'s own referrer, never to the buyer.');
        }
        if (FailedCommissionAttempt::where('purchase_id', $purchase->id)->exists()) {
            throw new LogicException('A purchase with a failed commission attempt has no commission.');
        }
        if ($this->base_amount_kobo !== $purchase->amount_kobo) {
            throw new LogicException('A commission is calculated from the purchase amount.');
        }
        if ($this->rate_bps === null || $this->rate_bps < 1 || $this->rate_bps > BasisPoints::MAX || $this->cap_kobo === null || $this->cap_kobo < 1
            || $this->amount_kobo === null || $this->amount_kobo < 1 || $this->amount_kobo !== self::amountFor($this->base_amount_kobo, $this->rate_bps, $this->cap_kobo)) {
            throw new LogicException('A commission is the rate of the purchase amount, capped and rounded down, and at least 1 kobo.');
        }
        $wallet = Wallet::find($this->wallet_id);
        if ($wallet === null || $wallet->user_id !== $this->referrer_id || $wallet->type !== WalletType::Main) {
            throw new LogicException('A commission is credited to the referrer\'s Main Wallet.');
        }
        $credit = Transaction::find($this->credit_transaction_id);
        $entries = $credit?->entries()->pluck('entry_type')->map(fn (LedgerEntryType $type) => $type->value)->all();
        if ($credit === null || $credit->wallet_id !== $wallet->id || $credit->user_id !== $this->referrer_id || $credit->type !== TransactionType::Commission
            || $credit->direction !== Direction::Credit || $credit->status !== TransactionStatus::Successful || $credit->amount_kobo !== $this->amount_kobo
            || $entries !== [LedgerEntryType::CommissionCredit->value]) {
            throw new LogicException('A commission\'s credit is one successful commission credit of its amount on the referrer\'s Main Wallet.');
        }
        if ($this->credited_at === null) {
            throw new LogicException('A commission records when it was credited.');
        }
    }
}
