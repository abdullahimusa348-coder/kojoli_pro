<?php

namespace App\Actions\Admin\Referrals;

use App\Models\CommissionSetting;
use App\Models\CommissionSettingChange;
use App\Models\Service;
use App\Models\SystemUser;
use App\Support\Enums\SystemPermission;
use App\Support\Pricing\BasisPoints;
use App\Support\Pricing\PricingLimits;
use App\Support\Referrals\QualifyingServices;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Sets the referral commission rate (basis points) and cap (kobo) of one
 * qualifying service (Phase 12), with the staff member's reason. A real
 * change saves the setting and writes exactly one append-only history row in
 * the same transaction; saving the values the service already has writes
 * nothing. The setting is created by its first save only, never in advance.
 * Saves of one service queue on that service's row, so different services
 * never hold each other up. A form opened before someone else's save (stale
 * fingerprint), or a first save that loses a race with another first save,
 * is refused with a reload message. Existing commissions are never
 * recalculated.
 */
class SaveCommissionSetting
{
    use DetectsConcurrencyErrors;

    public const STALE = 'These values were changed by someone else while you were editing. Reload the page and try again.';

    private const ATTEMPTS = 3;

    public function __construct(private ReferralRules $rules) {}

    /** @return bool whether anything changed */
    public function handle(Service $service, int $rateBps, int $capKobo, string $reason, SystemUser $actor, string $expectedFingerprint): bool
    {
        $this->rules->authorize($actor, SystemPermission::ReferralsManage);

        if (! QualifyingServices::includes($service->slug)) {
            throw new InvalidArgumentException('Referral commission applies only to the qualifying services.');
        }
        if ($rateBps < 0 || $rateBps > BasisPoints::MAX || $capKobo < 0 || $capKobo > PricingLimits::maxAmountKobo()) {
            throw new InvalidArgumentException('Invalid commission rate or cap.');
        }

        try {
            return DB::transaction(function () use ($service, $rateBps, $capKobo, $reason, $actor, $expectedFingerprint) {
                // Every save of this service queues here, on the service's own row. A first save has no setting row
                // to lock, and locking the missing one would lock the gap where other services' first rows go.
                Service::whereKey($service->id)->lockForUpdate()->firstOrFail();
                $settingId = CommissionSetting::where('service_id', $service->id)->value('id');
                $setting = $settingId === null ? null : CommissionSetting::whereKey($settingId)->lockForUpdate()->firstOrFail();

                if (! hash_equals(self::fingerprintOf($setting), $expectedFingerprint)) {
                    throw ValidationException::withMessages(['setting' => self::STALE]);
                }
                if ($setting !== null && $setting->rate_bps === $rateBps && $setting->cap_kobo === $capKobo) {
                    return false;
                }

                $old = $setting?->only(['rate_bps', 'cap_kobo']);
                $setting ??= (new CommissionSetting)->forceFill(['service_id' => $service->id]);
                $setting->forceFill(['rate_bps' => $rateBps, 'cap_kobo' => $capKobo, 'updated_by' => $actor->id])->save();

                (new CommissionSettingChange)->forceFill([
                    'commission_setting_id' => $setting->id,
                    'service_id' => $service->id,
                    'old_rate_bps' => $old['rate_bps'] ?? null,
                    'new_rate_bps' => $rateBps,
                    'old_cap_kobo' => $old['cap_kobo'] ?? null,
                    'new_cap_kobo' => $capKobo,
                    'reason' => $reason,
                    'changed_by' => $actor->id,
                ])->save();

                return true;
            }, self::ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            // Another first save created this service's setting at the same moment (service_id is unique).
            throw ValidationException::withMessages(['setting' => self::STALE]);
        } catch (QueryException $e) {
            // Still deadlocked or waiting on another save after the retries: the form is out of date.
            if ($this->causedByConcurrencyError($e)) {
                throw ValidationException::withMessages(['setting' => self::STALE]);
            }

            throw $e;
        }
    }

    /** Identifies the current rate and cap of a service, so a stale form cannot overwrite newer values. */
    public static function fingerprint(Service $service): string
    {
        return self::fingerprintOf(CommissionSetting::where('service_id', $service->id)->first());
    }

    private static function fingerprintOf(?CommissionSetting $setting): string
    {
        return sha1(json_encode([$setting?->rate_bps, $setting?->cap_kobo, $setting?->updated_at?->getTimestamp()]));
    }
}
