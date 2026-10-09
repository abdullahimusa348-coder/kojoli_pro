<?php

namespace App\Support\Kyc;

/**
 * The four KYC requirements the seeder creates (Phase 13), with their starting
 * text and order. Staff may change the name and description afterwards, and the
 * seeder never overwrites them. The starting text promises no check and no
 * service that does not exist yet.
 */
final class KycRequirementDefinitions
{
    /** @return list<array{key: string, type: KycRequirementType, label: string, description: string, position: int}> */
    public static function all(): array
    {
        return [
            ['key' => 'phone', 'type' => KycRequirementType::Phone, 'label' => 'Phone number',
                'description' => 'The phone number on the customer’s account.', 'position' => 10],
            ['key' => 'bvn', 'type' => KycRequirementType::Bvn, 'label' => 'BVN',
                'description' => 'The customer’s Bank Verification Number. Kept apart from the BVN purchase service, which never feeds KYC.', 'position' => 20],
            ['key' => 'nin', 'type' => KycRequirementType::Nin, 'label' => 'NIN',
                'description' => 'The customer’s National Identification Number. Kept apart from the NIN purchase service, which never feeds KYC.', 'position' => 30],
            ['key' => 'document', 'type' => KycRequirementType::Document, 'label' => 'Provider document',
                'description' => 'Which documents a provider requires is not set yet. Uploads are not available in this version.', 'position' => 40],
        ];
    }
}
