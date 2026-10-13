<?php

namespace App\Actions\Admin\Kyc;

use App\Models\KycRequirement;
use App\Models\KycRequirementChange;
use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use App\Support\Enums\UserType;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Saves one KYC requirement's settings (Phase 13 CP1): its name and description,
 * whether it is on, and the customer types it applies to, with the staff
 * member's reason. A real change saves the requirement and writes exactly one
 * append-only history row, before and after, in the same transaction; saving
 * what the requirement already says writes nothing. The requirement's row is
 * locked first, so saves of one requirement queue up. A form opened before
 * someone else's save is refused with a reload message, and nothing is written.
 * Turning a requirement on enforces nothing in this version.
 */
class SaveKycRequirement
{
    use DetectsConcurrencyErrors;

    public const STALE = 'These settings were changed by someone else while you were editing. Reload the page and try again.';

    private const ATTEMPTS = 3;

    public function __construct(private KycRules $rules) {}

    /**
     * @param  list<string>  $customerTypes  the customer types the requirement applies to, in any order
     * @return bool whether anything changed
     */
    public function handle(KycRequirement $requirement, string $label, ?string $description, bool $enabled, array $customerTypes,
        string $reason, SystemUser $actor, string $expectedFingerprint): bool
    {
        $this->rules->authorize($actor, SystemPermission::KycRequirements);

        if (array_diff($customerTypes, UserType::values()) !== []) {
            throw new InvalidArgumentException('KYC requirements apply to known customer types only.');
        }
        // The usual order, each type once: the same settings always give the same history.
        $types = array_values(array_intersect(UserType::values(), $customerTypes));
        if ($enabled && $types === []) {
            throw new InvalidArgumentException('A KYC requirement can be on only for at least one customer type.');
        }
        $length = mb_strlen($reason);
        if ($length < 10 || $length > 500) {
            throw new InvalidArgumentException('A KYC requirement change needs a reason of 10 to 500 characters.');
        }
        $labelLength = mb_strlen($label);
        if ($labelLength < 2 || $labelLength > 120 || ($description !== null && mb_strlen($description) > 500)) {
            throw new InvalidArgumentException('A KYC requirement has a name of 2 to 120 characters and a description of up to 500.');
        }

        try {
            return DB::transaction(function () use ($requirement, $label, $description, $enabled, $types, $reason, $actor, $expectedFingerprint) {
                // Every save of this requirement queues here, on its own row.
                $locked = KycRequirement::whereKey($requirement->id)->lockForUpdate()->firstOrFail();

                if (! hash_equals(self::fingerprint($locked), $expectedFingerprint)) {
                    throw ValidationException::withMessages(['requirement' => self::STALE]);
                }

                $before = $locked->snapshot();
                $after = array_replace($before, ['label' => $label, 'description' => $description, 'is_enabled' => $enabled, 'user_types' => $types]);
                if ($after === $before) {
                    return false;
                }

                $locked->forceFill(['label' => $label, 'description' => $description, 'is_enabled' => $enabled,
                    'user_types' => $types, 'updated_by' => $actor->id])->save();

                (new KycRequirementChange)->forceFill([
                    'kyc_requirement_id' => $locked->id,
                    'old_state' => $before,
                    'new_state' => $locked->snapshot(),
                    'reason' => $reason,
                    'changed_by' => $actor->id,
                ])->save();

                return true;
            }, self::ATTEMPTS);
        } catch (QueryException $e) {
            // Still deadlocked or waiting on another save after the retries: the form is out of date.
            if ($this->causedByConcurrencyError($e)) {
                throw ValidationException::withMessages(['requirement' => self::STALE]);
            }

            throw $e;
        }
    }

    /** Identifies a requirement's current settings, so a stale form cannot overwrite newer ones. */
    public static function fingerprint(KycRequirement $requirement): string
    {
        return sha1(json_encode([$requirement->snapshot(), $requirement->updated_at?->getTimestamp()]));
    }
}
