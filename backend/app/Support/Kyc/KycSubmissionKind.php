<?php

namespace App\Support\Kyc;

/**
 * What a KYC submission is for (Phase 13 CP2a). An initial submission follows a status that is not approved and asks for
 * every requirement that applies. A correction follows an approved status and asks only for the BVN or NIN it corrects.
 * Both are reviewed by staff; neither is verified against an official database.
 */
enum KycSubmissionKind: string
{
    case Initial = 'initial';
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Initial submission',
            self::Correction => 'Correction',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
