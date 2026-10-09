<?php

namespace App\Models;

use Database\Factories\KycSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A customer's KYC submission (Phase 13): what the customer sent for the
 * requirements at one moment. Immutable and never deleted; a later submission
 * never changes an earlier one. CP1 writes none.
 */
class KycSubmission extends Model
{
    /** @use HasFactory<KycSubmissionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::creating(fn (self $submission) => $submission->enforceInvariants());
        static::updating(fn () => throw new LogicException('KYC submissions are immutable.'));
        static::deleting(fn () => throw new LogicException('KYC submissions are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'submitted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    private function enforceInvariants(): void
    {
        if (preg_match('/^KYC-[0-9A-Z]{26}$/', (string) $this->reference) !== 1) {
            throw new LogicException('A KYC submission has a KYC- reference of 26 upper-case letters and digits.');
        }
        if ($this->user_id === null) {
            throw new LogicException('A KYC submission belongs to a customer.');
        }
    }
}
