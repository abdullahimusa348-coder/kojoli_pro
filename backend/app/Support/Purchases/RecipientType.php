<?php

namespace App\Support\Purchases;

use LogicException;

/**
 * Who or what a purchase is delivered to (Phase 11). Decided by the server
 * from the plan's locked service slug, never by the customer, and fixed once
 * the purchase exists.
 * - phone: Phase 10 meaning, for every historical purchase and every service
 *   other than NIN, BVN and Exam PIN; purchases.recipient holds the canonical
 *   phone.
 * - nin / bvn: an 11-digit NIN or BVN, kept only in
 *   purchase_identity_recipients (encrypted, masked, keyed hashes);
 *   purchases.recipient and request_fingerprint stay NULL.
 * - none (Phase 11 CP4, Exam PIN): the purchase has no customer-entered
 *   recipient of any kind; purchases.recipient and request_fingerprint stay
 *   NULL and there is no identity recipient. What it delivers is its result.
 * Smile Data has no recipient type yet: its inputs are not defined, so new
 * purchases of it are refused.
 * Every case states explicitly whether it is an identity and whether it
 * requires a result (exhaustive matches), so a new case can never inherit
 * NIN/BVN handling by accident.
 */
enum RecipientType: string
{
    case Phone = 'phone';
    case Nin = 'nin';
    case Bvn = 'bvn';
    case None = 'none';

    /** The type for a service, or null when that service cannot be purchased yet. */
    public static function forServiceSlug(string $slug): ?self
    {
        return match ($slug) {
            'nin' => self::Nin,
            'bvn' => self::Bvn,
            'exam-pin' => self::None,
            'smile-data' => null,
            default => self::Phone,
        };
    }

    /** NIN and BVN only: the purchase is bought for an 11-digit number, kept in purchase_identity_recipients. */
    public function isIdentity(): bool
    {
        return match ($this) {
            self::Nin, self::Bvn => true,
            self::Phone, self::None => false,
        };
    }

    /**
     * Whether the purchase is successful only with the result its provider
     * delivered (NIN, BVN and Exam PIN), and never refunded once it has one.
     */
    public function requiresResult(): bool
    {
        return match ($this) {
            self::Nin, self::Bvn, self::None => true,
            self::Phone => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Phone',
            self::Nin => 'NIN',
            self::Bvn => 'BVN',
            self::None => 'No recipient',
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
            throw new LogicException($this === self::Phone ? 'Phone recipients use NigerianPhone.' : 'This purchase has no recipient.');
        }
    }
}
