<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * The metadata of one document a KYC submission carries (Phase 13 CP2a). This row stores no file and serves none: it records the
 * private storage name (a server-generated ULID and an extension, never the client's file name), the verified type, the size (at most
 * 5 MiB) and a SHA-256 of the content. The storage name is hidden from serialisation, and no public URL exists. Written once and never
 * changed or removed.
 */
class KycDocument extends Model
{
    public const UPDATED_AT = null;

    /** The verified types and the extension each one is stored with. */
    public const TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public const MAX_BYTES = 5_242_880;

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['storage_name', 'sha256'];

    protected static function booted(): void
    {
        static::creating(fn (self $document) => $document->enforceInvariants());
        static::updating(fn () => throw new LogicException('KYC document records are never changed.'));
        static::deleting(fn () => throw new LogicException('KYC document records are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kyc_submission_id' => 'integer',
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<KycSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(KycSubmission::class, 'kyc_submission_id');
    }

    private function enforceInvariants(): void
    {
        if (KycReview::where('kyc_submission_id', $this->kyc_submission_id)->exists()) {
            throw new LogicException('A KYC submission takes no new document once it has a decision.');
        }
        $submission = KycSubmission::find($this->kyc_submission_id);
        if ($submission === null) {
            throw new LogicException('A KYC document belongs to a submission.');
        }
        if ($this->requirement_key !== 'document') {
            throw new LogicException('A KYC document answers the provider document requirement.');
        }
        if (! KycSubmissionRequirement::where('kyc_submission_id', $submission->id)->where('requirement_key', 'document')->exists()) {
            throw new LogicException('A KYC document belongs to a document requirement its submission asked for.');
        }

        $mime = (string) ($this->attributes['mime'] ?? '');
        $extension = self::TYPES[$mime] ?? null;
        if ($extension === null) {
            throw new LogicException('A KYC document is a PDF, a JPEG or a PNG.');
        }
        if (preg_match('/\A[0-9A-Z]{26}\.'.$extension.'\z/', (string) $this->storage_name) !== 1) {
            throw new LogicException('A KYC document is stored under a server-generated name with its own extension.');
        }
        $size = $this->size_bytes;
        if (! is_int($size) || $size < 1 || $size > self::MAX_BYTES) {
            throw new LogicException('A KYC document is between 1 byte and 5 MiB.');
        }
        if (! is_string($this->sha256) || preg_match('/\A[0-9a-f]{64}\z/', $this->sha256) !== 1) {
            throw new LogicException('A KYC document records the SHA-256 of its content.');
        }
        if ($this->uploaded_at === null) {
            throw new LogicException('A KYC document records when it was uploaded.');
        }
    }
}
