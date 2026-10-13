<?php

namespace App\Models;

use App\Support\Payments\GatewayMode;
use App\Support\Payments\GatewayStatus;
use Database\Factories\PaymentGatewayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An admin-created payment gateway record. The driver names an adapter in
 * config('payments.drivers'); endpoints and credential keys come from that
 * adapter. Code, status, mode and priority change only through the gateway
 * actions. Configuration status is derived (GatewayRegistry), never stored.
 */
class PaymentGateway extends Model
{
    /** @use HasFactory<PaymentGatewayFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'wallet_funding', 'settings'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => GatewayStatus::class,
            'mode' => GatewayMode::class,
            'priority' => 'integer',
            'wallet_funding' => 'boolean',
            'settings' => 'array',
        ];
    }

    /** @return HasMany<PaymentGatewayCredential, $this> */
    public function credentials(): HasMany
    {
        return $this->hasMany(PaymentGatewayCredential::class);
    }

    /** @return HasMany<PaymentGatewayCredentialChange, $this> */
    public function credentialChanges(): HasMany
    {
        return $this->hasMany(PaymentGatewayCredentialChange::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return list<string> credential keys stored for the mode */
    public function credentialKeysSet(GatewayMode $mode): array
    {
        return $this->credentials->filter(fn (PaymentGatewayCredential $c) => $c->mode === $mode)->map(fn ($c) => $c->key)->values()->all();
    }
}
