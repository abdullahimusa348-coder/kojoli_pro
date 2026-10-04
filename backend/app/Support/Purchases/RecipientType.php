<?php

namespace App\Support\Purchases;

use LogicException;

/**
 * Who or what a purchase is delivered to (Phase 11). Decided by the server
 * from the plan's locked service slug, never by the customer, and fixed once
 * the purchase exists.
 * - phone: Phase 10 meaning, for every historical purchase and every service
 *   other than NIN and BVN; purchases.recipient holds the canonical phone.
 * - nin / bvn: an 11-digit NIN or BVN, kept only in
 *   purchase_identity_recipients (encrypted, masked, keyed hashes);
 *   purchases.recipient and request_fingerprint stay NULL.
 * Exam PIN and Smile Data have no recipient type yet: their inputs are not
 * defined, so new purchases of them are refused.
 */
enum RecipientType: string
{
    case Phone = 'phone';
    case Nin = 'nin';
    case Bvn = 'bvn';

    /** The type for a service, or null when that service cannot be purchased yet. */
    public static function forServiceSlug(string $slug): ?self
    {
        return match ($slug) {
            'nin' => self::Nin,
            'bvn' => self::Bvn,
            'exam-pin', 'smile-data' => null,
            default => self::Phone,
        };
    }

    public function isIdentity(): bool
    {
        return $this !== self::Phone;
    }

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Phone',
            self::Nin => 'NIN',
            self::Bvn => 'BVN',
        };
    }

    /** The canonical 11 digits (whitespace ignored), or null when the input is anything else. NIN and BVN only. */
    public function normalize(#[\SensitiveParameter] ?string $input): ?string
    {
        $this->assertIdentity();
        if ($input === null) {
            return null;
        }
        $compact = preg_replace('/\s+/', '', $input);

        return preg_match('/\A\d{11}\z/', $compact) ? $compact : null;
    }

    public function isCanonical(#[\SensitiveParameter] ?string $value): bool
    {
        return $value !== null && $this->normalize($value) === $value;
    }

    /** The only form that may be displayed: seven dots and the last four digits. */
    public function mask(#[\SensitiveParameter] string $canonical): string
    {
        $this->assertIdentity();

        return '•••••••'.substr($canonical, -4);
    }

    public function invalidMessage(): string
    {
        $this->assertIdentity();

        return "Enter a valid 11-digit {$this->label()}.";
    }

    private function assertIdentity(): void
    {
        if (! $this->isIdentity()) {
            throw new LogicException('Phone recipients use NigerianPhone.');
        }
    }
}
