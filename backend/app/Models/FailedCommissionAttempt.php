<?php

namespace App\Models;

use App\Support\Purchases\PurchaseStatus;
use App\Support\Referrals\CommissionFailureReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A payable referral commission that could not be credited (Phase 12): the
 * purchase, the referrer (shown to staff as the internal customer ID), a
 * fixed reason code and the time. No money and no other detail. Written in
 * the transaction that marks the purchase successful; never for an
 * eligibility exclusion or a database-level abort; never retried, changed or
 * deleted. Staff may compensate with an ordinary wallet adjustment.
 */
class FailedCommissionAttempt extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::creating(fn (self $attempt) => $attempt->enforceInvariants());
        static::updating(fn () => throw new LogicException('Failed commission attempts are append-only.'));
        static::deleting(fn () => throw new LogicException('Failed commission attempts are append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purchase_id' => 'integer',
            'referrer_id' => 'integer',
            'reason_code' => CommissionFailureReason::class,
        ];
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

    private function enforceInvariants(): void
    {
        if (! $this->reason_code instanceof CommissionFailureReason) {
            throw new LogicException('A failed commission attempt has one of the fixed reason codes.');
        }
        $purchase = Purchase::find($this->purchase_id);
        if ($purchase === null || $purchase->status !== PurchaseStatus::Successful) {
            throw new LogicException('A failed commission attempt belongs to a successful purchase.');
        }
        $referral = Referral::where('referred_user_id', $purchase->user_id)->first();
        if ($referral === null || $referral->referrer_id !== $this->referrer_id) {
            throw new LogicException('A failed commission attempt names the buyer\'s own referrer.');
        }
        if (Commission::where('purchase_id', $purchase->id)->exists()) {
            throw new LogicException('A purchase with a commission has no failed commission attempt.');
        }
    }
}
