<?php

namespace App\Models;

use App\Support\Enums\SystemPermission;
use App\Support\Enums\UserStatus;
use App\Support\Kyc\KycApprovalGate;
use App\Support\Kyc\KycApprovalRefusal;
use App\Support\Kyc\KycIdentityNumber;
use App\Support\Kyc\KycSelfReview;
use App\Support\Kyc\KycStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The staff decision on one KYC submission (Phase 13 CP2a): approved, rejected or more information requested, with the reason the
 * customer will see (trimmed first, then a rejection or a request for more information needs 10 to 500 characters with at least one letter), the staff
 * member who decided and the time. One decision per submission, ever: a unique key, the submission's open slot and the rules here
 * make conflicting decisions impossible. Never changed or deleted.
 */
class KycReview extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['form_key'];

    protected static function booted(): void
    {
        static::creating(function (self $review) {
            $review->trimReason();
            $review->enforceInvariants();
        });
        static::updating(fn () => throw new LogicException('KYC review decisions are append-only.'));
        static::deleting(fn () => throw new LogicException('KYC review decisions are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kyc_submission_id' => 'integer',
            'decision' => KycStatus::class,
            'reviewed_by' => 'integer',
            'decided_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<KycSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(KycSubmission::class, 'kyc_submission_id');
    }

    /** @return BelongsTo<SystemUser, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'reviewed_by')->withTrashed();
    }

    /**
     * Trims the reason before any rule is checked, as the ledger does, so padding never counts toward a length or a letter. A blank
     * approval reason is no reason at all, which is how the ledger stores it. Trimming uses PHP's trim() with its default characters at
     * both ends; other whitespace, such as a no-break space, is kept.
     */
    private function trimReason(): void
    {
        $reason = $this->attributes['reason'] ?? null;
        if ($reason === null) {
            return;
        }

        $trimmed = trim((string) $reason);
        $decision = KycStatus::tryFrom((string) ($this->attributes['decision'] ?? ''));
        $this->attributes['reason'] = $trimmed === '' && $decision === KycStatus::Approved ? null : $trimmed;
    }

    private function enforceInvariants(): void
    {
        // The checks below read the submission, the customer and the reviewer under locks. The locks last until the caller's transaction
        // ends. Outside a transaction they would be released before this row is written, so a phone or reviewer change could land between.
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A KYC review is recorded inside a transaction, so its checks and its row commit together.');
        }
        // The ledger's order: the submission (shared), the customer (exclusive), then the reviewer (shared, below). A decision made through
        // the ledger already holds these locks, so reading them again changes nothing.
        $submission = KycSubmission::query()->whereKey($this->kyc_submission_id)->sharedLock()->first();
        if ($submission === null) {
            throw new LogicException('A KYC review belongs to a submission.');
        }
        $customer = User::query()->whereKey($submission->user_id)->lockForUpdate()->first();
        if (self::where('kyc_submission_id', $this->kyc_submission_id)->exists()) {
            throw new LogicException('A KYC submission has one decision, ever.');
        }

        $decision = KycStatus::tryFrom((string) ($this->attributes['decision'] ?? ''));
        if ($decision === null || ! $decision->isDecision()) {
            throw new LogicException('A KYC review is an approval, a rejection or a request for more information.');
        }
        // The approval gate in the ledger refuses this first, with a message that names the item. The same rules are repeated here,
        // and the database repeats the structural ones, because a review is permanent: no caller can record an approval this version
        // cannot back.
        if ($decision === KycStatus::Approved) {
            $refusal = KycApprovalGate::refusal($submission, $customer);
            if ($refusal !== null) {
                throw new LogicException(match ($refusal->rule) {
                    KycApprovalRefusal::NO_REQUIREMENTS => 'A KYC approval needs a submission that asked for at least one requirement.',
                    KycApprovalRefusal::STALE_PHONE => 'A KYC approval needs the phone number the submission was sent with.',
                    KycApprovalRefusal::UNSUPPORTED => 'A KYC approval in this version needs a submission that asked only for a BVN or a NIN.',
                    default => 'A KYC approval needs a BVN or NIN identity value that matches its requirement.',
                });
            }
        }
        $reason = $this->attributes['reason'] ?? null;
        if ($reason !== null && KycIdentityNumber::containsIdentityNumber((string) $reason)) {
            throw new LogicException('A KYC review reason cannot contain an 11-digit number such as a BVN, NIN or phone number.');
        }
        $length = $reason === null ? 0 : mb_strlen((string) $reason);
        if ($decision === KycStatus::Approved) {
            if ($reason !== null && ($length < 1 || $length > 500)) {
                throw new LogicException('An approval keeps an optional reason of up to 500 characters.');
            }
        } elseif ($reason === null || $length < 10 || $length > 500 || preg_match('/\p{L}/u', (string) $reason) !== 1) {
            throw new LogicException('A rejection or a request for more information needs a reason of 10 to 500 characters with at least one letter.');
        }

        $reviewer = SystemUser::query()->whereKey($this->reviewed_by)->where('status', UserStatus::Active->value)->sharedLock()->first();
        if ($reviewer === null) {
            throw new LogicException('A KYC review is decided by an active staff member.');
        }
        if (! $reviewer->can(SystemPermission::KycReview->value)) {
            throw new LogicException('A KYC review is decided by a staff member who may review KYC submissions.');
        }
        if ($customer !== null && KycSelfReview::sameContact($customer, $reviewer)) {
            throw new LogicException('A KYC review cannot be decided by the staff member who holds the same contact details as the customer.');
        }
        if ($this->decided_at === null) {
            throw new LogicException('A KYC review records when it was decided.');
        }
        if (! is_string($this->form_key) || strlen($this->form_key) !== 36 || ! Str::isUuid($this->form_key)) {
            throw new LogicException('A KYC review keeps its one-time form key (a UUID).');
        }
    }
}
