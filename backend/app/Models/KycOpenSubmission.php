<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The open slot of a customer's KYC submission (Phase 13 CP2a): one row per customer while a submission awaits its decision, keyed
 * by the customer. The customer is the primary key, so the database itself allows at most one open submission per customer, however
 * many requests race. The row is created with its submission and removed only once that submission has its decision: the release trigger removes it in the same
 * transaction. It is not history: the submission and its review are. It is never changed.
 */
class KycOpenSubmission extends Model
{
    protected $table = 'kyc_open_submissions';

    protected $primaryKey = 'user_id';

    protected $keyType = 'int';

    public $incrementing = false;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::creating(fn (self $slot) => $slot->enforceInvariants());
        static::updating(fn () => throw new LogicException('An open KYC submission is not changed; it is released once decided.'));
        static::deleting(fn (self $slot) => $slot->assertDecided());
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'kyc_submission_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<KycSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(KycSubmission::class, 'kyc_submission_id');
    }

    private function enforceInvariants(): void
    {
        $submission = KycSubmission::find($this->kyc_submission_id);
        if ($submission === null || (int) $submission->user_id !== (int) $this->user_id) {
            throw new LogicException('An open KYC submission belongs to its customer.');
        }
        if (KycReview::where('kyc_submission_id', $submission->id)->exists()) {
            throw new LogicException('A decided KYC submission is not open.');
        }
    }

    private function assertDecided(): void
    {
        if (! KycReview::where('kyc_submission_id', $this->kyc_submission_id)->exists()) {
            throw new LogicException('A KYC submission leaves the open slot only once it has a decision.');
        }
    }
}
