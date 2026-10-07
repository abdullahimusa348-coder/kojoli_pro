<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The permanent link between a referrer and the customer they referred
 * (Phase 12), made only during that customer's registration. A customer has
 * at most one referrer (unique) and can never refer themselves. Never
 * changed, reassigned or deleted, whatever later happens to either account.
 */
class Referral extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::creating(fn (self $referral) => $referral->enforceInvariants());
        static::updating(fn () => throw new LogicException('Referral links are permanent.'));
        static::deleting(fn () => throw new LogicException('Referral links are permanent.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'referrer_id' => 'integer',
            'referred_user_id' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function referred(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    private function enforceInvariants(): void
    {
        if ($this->referrer_id === null || $this->referred_user_id === null) {
            throw new LogicException('A referral links a referrer and the customer they referred.');
        }
        if ($this->referrer_id === $this->referred_user_id) {
            throw new LogicException('A customer can never refer themselves.');
        }
    }
}
