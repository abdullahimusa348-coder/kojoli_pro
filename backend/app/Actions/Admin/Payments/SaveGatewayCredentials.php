<?php

namespace App\Actions\Admin\Payments;

use App\Exceptions\Payments\PaymentException;
use App\Models\PaymentGateway;
use App\Models\PaymentGatewayCredential;
use App\Models\PaymentGatewayCredentialChange;
use App\Models\SystemUser;
use App\Services\Payments\GatewayRegistry;
use App\Support\Enums\SystemPermission;
use App\Support\Payments\GatewayMode;
use Illuminate\Support\Facades\DB;

/**
 * Write-only gateway credentials per mode (payments.credentials). Only keys
 * the adapter declares are accepted. Values are stored encrypted with a
 * last-four hint; blank inputs keep the current value; every set,
 * replacement and clear is recorded without the value. Values are never
 * returned, logged or put in messages.
 */
class SaveGatewayCredentials
{
    public function __construct(private PaymentRules $rules, private GatewayRegistry $registry) {}

    /**
     * @param  array<string, ?string>  $values  blank entries are ignored
     * @return int number of credentials set or replaced
     */
    public function handle(PaymentGateway $gateway, GatewayMode $mode, array $values, SystemUser $actor): int
    {
        $this->rules->authorize($actor, SystemPermission::PaymentsCredentials);
        $keys = $this->keys($gateway);

        return DB::transaction(function () use ($gateway, $mode, $values, $actor, $keys) {
            $count = 0;
            foreach ($keys as $key) {
                $value = $values[$key] ?? null;
                if (! is_string($value) || $value === '') {
                    continue;
                }

                $credential = PaymentGatewayCredential::where('payment_gateway_id', $gateway->id)->where('mode', $mode->value)
                    ->where('key', $key)->lockForUpdate()->first();
                $action = $credential === null ? 'set' : 'replaced';
                $credential ??= (new PaymentGatewayCredential)->forceFill(['payment_gateway_id' => $gateway->id, 'mode' => $mode, 'key' => $key]);
                $credential->forceFill(['value' => $value, 'hint' => PaymentGatewayCredential::hintFor($value), 'updated_by' => $actor->id])->save();
                self::record($gateway, $mode, $key, $action, $actor);
                $count++;
            }

            return $count;
        });
    }

    /** Removes one stored credential. Returns false when nothing was stored. */
    public function clear(PaymentGateway $gateway, GatewayMode $mode, string $key, SystemUser $actor): bool
    {
        $this->rules->authorize($actor, SystemPermission::PaymentsCredentials);
        if (! in_array($key, $this->keys($gateway), true)) {
            throw new PaymentException('Unknown credential.');
        }

        return DB::transaction(function () use ($gateway, $mode, $key, $actor) {
            $deleted = PaymentGatewayCredential::where('payment_gateway_id', $gateway->id)->where('mode', $mode->value)->where('key', $key)->delete();
            if ($deleted === 0) {
                return false;
            }
            self::record($gateway, $mode, $key, 'cleared', $actor);

            return true;
        });
    }

    /** @return list<string> */
    private function keys(PaymentGateway $gateway): array
    {
        $adapter = $this->registry->adapterFor($gateway) ?? throw new PaymentException('This gateway driver is not available.');

        return $adapter->credentialKeys();
    }

    private static function record(PaymentGateway $gateway, GatewayMode $mode, string $key, string $action, SystemUser $actor): void
    {
        (new PaymentGatewayCredentialChange)->forceFill([
            'payment_gateway_id' => $gateway->id,
            'mode' => $mode,
            'key' => $key,
            'action' => $action,
            'changed_by' => $actor->id,
        ])->save();
    }
}
