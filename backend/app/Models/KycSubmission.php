<?php

namespace App\Models;

use App\Support\Enums\UserType;
use App\Support\Kyc\KycSubmissionKind;
use Database\Factories\KycSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use LogicException;

/**
 * A customer's KYC submission (Phase 13): what the customer sent at one moment, for the requirements that applied then. Immutable and
 * never deleted: a later submission never changes an earlier one, and its decision (KycReview) is the only thing that follows it.
 * CP2a records the kind (initial or correction), the customer type it was sent under and a one-time form key. Its requirements and
 * identity values are KycSubmissionRequirement and KycSubmissionIdentity rows; this row holds no identity number.
 */
class KycSubmission extends Model
{
    /** @use HasFactory<KycSubmissionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['form_key'];

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
            'kind' => KycSubmissionKind::class,
            'user_type' => UserType::class,
            'submitted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<KycSubmissionRequirement, $this> */
    public function requirements(): HasMany
    {
        return $this->hasMany(KycSubmissionRequirement::class, 'kyc_submission_id');
    }

    /** @return HasMany<KycSubmissionIdentity, $this> */
    public function identities(): HasMany
    {
        return $this->hasMany(KycSubmissionIdentity::class, 'kyc_submission_id');
    }

    /** @return HasOne<KycReview, $this> */
    public function review(): HasOne
    {
        return $this->hasOne(KycReview::class, 'kyc_submission_id');
    }

    private function enforceInvariants(): void
    {
        if (preg_match('/^KYC-[0-9A-Z]{26}$/', (string) $this->reference) !== 1) {
            throw new LogicException('A KYC submission has a KYC- reference of 26 upper-case letters and digits.');
        }
        if ($this->user_id === null) {
            throw new LogicException('A KYC submission belongs to a customer.');
        }
        if (KycSubmissionKind::tryFrom((string) ($this->attributes['kind'] ?? '')) === null) {
            throw new LogicException('A KYC submission is an initial submission or a correction.');
        }
        if (UserType::tryFrom((string) ($this->attributes['user_type'] ?? '')) === null) {
            throw new LogicException('A KYC submission records the customer type it was sent under.');
        }
        if (! is_string($this->form_key) || strlen($this->form_key) !== 36 || ! Str::isUuid($this->form_key)) {
            throw new LogicException('A KYC submission keeps its one-time form key (a UUID).');
        }
        if ($this->submitted_at === null) {
            throw new LogicException('A KYC submission records when it was sent.');
        }
    }
}
