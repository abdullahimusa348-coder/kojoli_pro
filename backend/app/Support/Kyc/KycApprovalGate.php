<?php

namespace App\Support\Kyc;

use App\Models\KycSubmission;
use App\Models\KycSubmissionIdentity;
use App\Models\KycSubmissionRequirement;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * The rules an approval must pass (Phase 13 CP2a), shared by the ledger and the review model so both refuse the same things. A submission
 * can be approved only if it asked for at least one requirement, every requirement is a BVN or a NIN (a phone verification and a provider
 * document cannot be completed in this version), its phone is unchanged since it was sent when it asked for one, and each BVN or NIN has
 * an identity value that matches its requirement. The identity check decrypts the value in memory and compares its mask and lookup hash
 * under every app key still configured. Each identity value is matched to its own requirement by key, as the database trigger matches
 * it. The rows are read under shared locks, so they are the latest committed rows; the caller holds the submission and customer locks.
 * Nothing is returned, logged or put in a message, and a value that cannot be decrypted is refused rather than passed.
 */
final class KycApprovalGate
{
    /** The first rule the submission breaks, or null when an approval may be recorded. */
    public static function refusal(KycSubmission $submission, ?User $customer): ?KycApprovalRefusal
    {
        $rows = KycSubmissionRequirement::where('kyc_submission_id', $submission->id)->sharedLock()->get();
        if ($rows->isEmpty()) {
            return KycApprovalRefusal::noRequirements();
        }

        foreach ($rows as $row) {
            if ($row->requirement_type === KycRequirementType::Phone
                && ! KycContactFingerprint::matches((string) $row->phone_fingerprint, (string) $customer?->phone)) {
                return KycApprovalRefusal::stalePhone();
            }
        }

        // Fail closed: only the types named here are approvable, so a type added later is refused until it is named.
        foreach ($rows as $row) {
            if (! in_array($row->requirement_type, [KycRequirementType::Bvn, KycRequirementType::Nin], true)) {
                return KycApprovalRefusal::unsupported($row->requirement_type);
            }
        }

        $identities = KycSubmissionIdentity::where('kyc_submission_id', $submission->id)->sharedLock()->get()->keyBy('requirement_key');
        foreach ($rows as $row) {
            $identity = $identities->get($row->requirement_key);
            if ($identity === null) {
                return KycApprovalRefusal::identityMissing($row->requirement_type);
            }
            if (! self::identityMatches($identity, $row->requirement_type)) {
                return KycApprovalRefusal::identityMismatch($row->requirement_type);
            }
        }

        return null;
    }

    private static function identityMatches(KycSubmissionIdentity $identity, KycRequirementType $type): bool
    {
        try {
            $number = (string) $identity->ciphertext;
        } catch (DecryptException) {
            return false;
        }
        if (! KycIdentityNumber::isCanonical($number) || $identity->masked_value !== KycIdentityNumber::mask($number)) {
            return false;
        }

        // The hash was made under the key that was current when the value was written, so every key still configured is tried.
        foreach (KycIdentityNumber::lookupHashes($type, $number) as $lookupHash) {
            if (hash_equals((string) $identity->lookup_hash, $lookupHash)) {
                return true;
            }
        }

        return false;
    }
}
