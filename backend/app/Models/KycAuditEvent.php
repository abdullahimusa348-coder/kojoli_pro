<?php

namespace App\Models;

use App\Support\Kyc\KycAuditEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One entry in the KYC audit trail (Phase 13 CP2a): what happened, to which customer and submission, which staff member acted (when one
 * did) and which document (when one is concerned). The application enforces its metadata: the exact keys of each event, text values
 * from closed lists, and two whole-number counts that must repeat a value the records hold. requirement_count must equal the number
 * of requirement rows its submission holds, and size_bytes must equal the stored size of the event's document. The database checks
 * none of the keys, values or counts (see the migration that creates this table). A raw SQL insert skips these checks, so the two
 * equalities hold only for rows this model writes. Apart from its own row id, its only numbers are the two metadata counts and the
 * integer identifiers of its customer, submission, document and staff member. Those identifiers identify records and people, but they
 * are not identity values: no BVN or NIN value is stored in any column or in metadata. Append-only: never changed or removed.
 */
class KycAuditEvent extends Model
{
    public const UPDATED_AT = null;

    /** The only text values metadata may hold, by key. Counts and sizes are whole numbers, each checked against its record. */
    private const VALUES = [
        'kind' => ['initial', 'correction'],
        'decision' => ['approved', 'rejected', 'more_info_requested'],
        'requirement_key' => ['document'],
        'mime' => ['application/pdf', 'image/jpeg', 'image/png'],
        'action' => ['view', 'download'],
    ];

    /** Whole numbers that must repeat a value their records hold (see assertCountsMatchRecords). */
    private const COUNTS = ['requirement_count', 'size_bytes'];

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::creating(fn (self $event) => $event->enforceInvariants());
        static::updating(fn () => throw new LogicException('KYC audit events are append-only.'));
        static::deleting(fn () => throw new LogicException('KYC audit events are append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'kyc_submission_id' => 'integer',
            'kyc_document_id' => 'integer',
            'system_user_id' => 'integer',
            'event' => KycAuditEventType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<SystemUser, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'system_user_id')->withTrashed();
    }

    private function enforceInvariants(): void
    {
        $event = KycAuditEventType::tryFrom((string) ($this->attributes['event'] ?? ''));
        if ($event === null) {
            throw new LogicException('A KYC audit event is one of the events the trail records.');
        }
        if (! User::query()->whereKey($this->user_id)->exists()) {
            throw new LogicException('A KYC audit event concerns a customer.');
        }

        if ($this->kyc_submission_id === null) {
            throw new LogicException('A KYC audit event concerns a submission.');
        }
        $submission = KycSubmission::find($this->kyc_submission_id);
        if ($submission === null || (int) $submission->user_id !== (int) $this->user_id) {
            throw new LogicException('A KYC audit event about a submission concerns its own customer.');
        }

        $document = $this->kyc_document_id === null ? null : KycDocument::find($this->kyc_document_id);
        if ($this->kyc_document_id !== null && ($document === null || (int) $document->kyc_submission_id !== (int) $submission->id)) {
            throw new LogicException('A KYC audit event about a document names the submission the document belongs to.');
        }
        if ($event->concernsDocument() !== ($document !== null)) {
            throw new LogicException('A KYC audit event names a document exactly when it concerns one.');
        }

        if ($event->isStaffAction()) {
            if ($this->system_user_id === null || ! SystemUser::query()->whereKey($this->system_user_id)->exists()) {
                throw new LogicException('A staff action names the staff member who took it.');
            }
        } elseif ($this->system_user_id !== null) {
            throw new LogicException('A customer event names no staff member.');
        }

        $this->assertMetadata($event);
        $this->assertCountsMatchRecords($event, $submission, $document);
    }

    private function assertMetadata(KycAuditEventType $event): void
    {
        $metadata = $this->metadata;
        if (! is_array($metadata)) {
            throw new LogicException('A KYC audit event holds named metadata.');
        }

        $keys = array_keys($metadata);
        $allowed = $event->metadataKeys();
        sort($keys);
        sort($allowed);
        if ($keys !== $allowed) {
            throw new LogicException('A KYC audit event holds exactly the metadata its kind allows.');
        }

        foreach ($metadata as $key => $value) {
            if (in_array($key, self::COUNTS, true)) {
                if (! is_int($value) || $value < 0) {
                    throw new LogicException("A KYC audit event's {$key} is a whole number.");
                }

                continue;
            }
            if (! is_string($value) || ! in_array($value, self::VALUES[$key] ?? [], true)) {
                throw new LogicException("A KYC audit event's {$key} is one of its allowed values.");
            }
        }
    }

    /**
     * Each count repeats a value its records hold: requirement_count the requirement rows of the submission, size_bytes the stored size of
     * the event's document. A submission with no requirement row, or a document without a stored size, refuses the event, so a count that
     * cannot be checked is never written.
     */
    private function assertCountsMatchRecords(KycAuditEventType $event, KycSubmission $submission, ?KycDocument $document): void
    {
        $metadata = $this->metadata;

        if ($event === KycAuditEventType::SubmissionOpened) {
            $held = $submission->requirements()->count();
            if ($held < 1) {
                throw new LogicException('A KYC audit event about a submission needs a requirement row to check its count against.');
            }
            if ($metadata['requirement_count'] !== $held) {
                throw new LogicException("A KYC audit event's requirement_count is the number of requirement rows its submission holds.");
            }
        }

        if ($event === KycAuditEventType::DocumentRegistered) {
            if ($document === null || ! is_int($document->size_bytes)) {
                throw new LogicException('A KYC audit event about a document needs its stored size to check size_bytes against.');
            }
            if ($metadata['size_bytes'] !== $document->size_bytes) {
                throw new LogicException("A KYC audit event's size_bytes is the stored size of its document.");
            }
        }
    }
}
