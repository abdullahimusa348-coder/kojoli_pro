<?php

namespace App\Actions\Admin\Payments;

use App\Exceptions\Payments\PaymentException;
use App\Models\PaymentGateway;
use App\Models\SystemUser;
use App\Services\Payments\GatewayRegistry;
use App\Support\Enums\SystemPermission;
use App\Support\Payments\GatewayMode;
use App\Support\Payments\GatewayStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Creates and edits gateway records (payments.gateways). The driver must be
 * an adapter that exists in code; endpoints come from the adapter and can
 * never be entered here. New gateways start inactive, in sandbox mode, last
 * in priority. Code and driver never change after creation.
 */
class SaveGateway
{
    public function __construct(private PaymentRules $rules, private GatewayRegistry $registry) {}

    /** @param  array{name: string, code: string, driver: string, wallet_funding?: bool, settings?: array<string, mixed>}  $data */
    public function create(array $data, SystemUser $actor): PaymentGateway
    {
        $this->rules->authorize($actor, SystemPermission::PaymentsGateways);
        if (! array_key_exists($data['driver'], $this->registry->adapters())) {
            throw new PaymentException('This driver is not available.');
        }
        $settings = $this->settings($data['driver'], $data['settings'] ?? []);

        return DB::transaction(function () use ($data, $settings) {
            $gateway = (new PaymentGateway)->forceFill([
                'name' => $data['name'],
                'code' => $data['code'],
                'driver' => $data['driver'],
                'status' => GatewayStatus::Inactive,
                'mode' => GatewayMode::Sandbox,
                'priority' => (int) PaymentGateway::lockForUpdate()->max('priority') + 1,
                'wallet_funding' => (bool) ($data['wallet_funding'] ?? true),
                'settings' => $settings ?: null,
            ]);
            $gateway->save();

            return $gateway;
        });
    }

    /** @param  array{name: string, wallet_funding?: bool, settings?: array<string, mixed>}  $data */
    public function update(PaymentGateway $gateway, array $data, SystemUser $actor): PaymentGateway
    {
        $this->rules->authorize($actor, SystemPermission::PaymentsGateways);
        $settings = $this->settings($gateway->driver, $data['settings'] ?? []);

        $gateway->forceFill([
            'name' => $data['name'],
            'wallet_funding' => (bool) ($data['wallet_funding'] ?? false),
            'settings' => $settings ?: null,
        ])->save();

        return $gateway;
    }

    /** Swaps priority with the neighbour above or below. Returns false when already first or last. */
    public function move(PaymentGateway $gateway, string $direction, SystemUser $actor): bool
    {
        $this->rules->authorize($actor, SystemPermission::PaymentsGateways);

        return DB::transaction(function () use ($gateway, $direction) {
            $all = PaymentGateway::orderBy('priority')->lockForUpdate()->get()->values();
            $index = $all->search(fn ($g) => $g->id === $gateway->id);
            $neighbour = $index === false ? null : $all->get($direction === 'up' ? $index - 1 : $index + 1);
            if ($neighbour === null) {
                return false;
            }
            $current = $all[$index];
            [$a, $b] = [$current->priority, $neighbour->priority];

            // Temporary priority 0 (never used otherwise) keeps the unique index valid.
            $current->forceFill(['priority' => 0])->save();
            $neighbour->forceFill(['priority' => $a])->save();
            $current->forceFill(['priority' => $b])->save();

            return true;
        });
    }

    /**
     * Only the setting keys the adapter declares, validated with its rules.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function settings(string $driver, array $input): array
    {
        $rules = ($this->registry->adapters()[$driver] ?? null)?->settingsRules() ?? [];
        $values = array_intersect_key($input, $rules);

        return array_filter(Validator::make($values, $rules)->validate(), fn ($v) => $v !== null && $v !== '');
    }
}
