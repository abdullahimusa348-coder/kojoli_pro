<?php

namespace App\Support\Kyc;

/**
 * The kinds of KYC requirement (Phase 13). Each kind exists once in code; how a
 * requirement is configured (on or off, customer types, text) is data. A
 * document requirement names no document kind: kinds wait for decision D16, and
 * no ID card is assumed.
 */
enum KycRequirementType: string
{
    case Phone = 'phone';
    case Bvn = 'bvn';
    case Nin = 'nin';
    case Document = 'document';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Phone number',
            self::Bvn => 'BVN',
            self::Nin => 'NIN',
            self::Document => 'Provider document',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
