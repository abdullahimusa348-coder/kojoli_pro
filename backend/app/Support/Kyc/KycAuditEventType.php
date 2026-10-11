<?php

namespace App\Support\Kyc;

/**
 * The events the KYC audit trail records (Phase 13 CP2a). Each event lists the only metadata keys it may carry, and its text values
 * come from closed lists. requirement_count (submission.opened) must equal the number of requirement rows its submission holds, and
 * size_bytes (document.registered) must equal the stored size of its document, so each count repeats a value the records already hold.
 * KycAuditEvent enforces all of this; the database checks none of the keys, values or counts. Staff events name the staff member who
 * acted; customer events name none.
 */
enum KycAuditEventType: string
{
    case SubmissionOpened = 'submission.opened';
    case ReviewDecided = 'review.decided';
    case DocumentRegistered = 'document.registered';
    case DocumentAccessed = 'document.accessed';

    /** @return list<string> */
    public function metadataKeys(): array
    {
        return match ($this) {
            self::SubmissionOpened => ['kind', 'requirement_count'],
            self::ReviewDecided => ['decision'],
            self::DocumentRegistered => ['requirement_key', 'mime', 'size_bytes'],
            self::DocumentAccessed => ['action'],
        };
    }

    /** Whether a staff member performed the event. */
    public function isStaffAction(): bool
    {
        return in_array($this, [self::ReviewDecided, self::DocumentAccessed], true);
    }

    /** Whether the event concerns a stored document. */
    public function concernsDocument(): bool
    {
        return in_array($this, [self::DocumentRegistered, self::DocumentAccessed], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
