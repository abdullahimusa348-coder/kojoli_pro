<?php

namespace App\Models;

use App\Exceptions\Payments\PaymentException;
use App\Support\Payments\GatewayMode;
use App\Support\Payments\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One wallet-funding payment with one gateway. A payment is not money
 * movement: when verified with the gateway (exact amount and currency) it is
 * credited through WalletService, and wallet_transaction_id points to that
 * single funding transaction. Written only by PaymentService. The reference,
 * customer, wallet, gateway, mode, amount and idempotency key never change;
 * status changes follow PaymentStatus::canTransitionTo(). Never deleted.
 */
class Payment extends Model
{
    /** @var list<string> */
    protected $fillable = [];

    private const IMMUTABLE = ['reference', 'user_id', 'wallet_id', 'payment_gateway_id', 'mode', 'amount_kobo', 'currency', 'idempotency_key'];

    protected static function booted(): void
    {
        static::updating(function (self $payment) {
            $locked = array_intersect(array_keys($payment->getDirty()), self::IMMUTABLE);
            if ($locked !== []) {
                throw new LogicException('Payment fields are immutable: '.implode(', ', $locked).'.');
            }
            if ($payment->isDirty('wallet_transaction_id') && $payment->getRawOriginal('wallet_transaction_id') !== null) {
                throw new LogicException('A payment is credited at most once.');
            }
            if ($payment->isDirty('status')) {
                $from = PaymentStatus::from($payment->getRawOriginal('status'));
                if (! $from->canTransitionTo($payment->status)) {
                    throw new PaymentException("A {$from->value} payment cannot become {$payment->status->value}.");
                }
            }
        });
        static::deleting(fn () => throw new LogicException('Payments are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mode' => GatewayMode::class,
            'status' => PaymentStatus::class,
            'amount_kobo' => 'integer',
            'verified_amount_kobo' => 'integer',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /** @return BelongsTo<PaymentGateway, $this> */
    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'payment_gateway_id');
    }

    /** @return BelongsTo<Transaction, $this> */
    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'wallet_transaction_id');
    }

    /** @return HasMany<PaymentStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(PaymentStatusChange::class)->orderBy('id');
    }

    /** @return HasMany<PaymentWebhook, $this> */
    public function webhooks(): HasMany
    {
        return $this->hasMany(PaymentWebhook::class)->orderBy('id');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [PaymentStatus::Successful, PaymentStatus::Failed], true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
