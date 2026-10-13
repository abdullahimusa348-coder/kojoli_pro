<?php

namespace App\Models;

use App\Support\Payments\WebhookOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One inbound webhook event (unique per gateway and event key). Only a
 * sanitized, allow-listed payload is kept; it may later be cleared by the
 * retention prune. Otherwise only the processing outcome may change. Never deleted.
 */
class PaymentWebhook extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    private const MUTABLE = ['outcome', 'payment_id', 'processed_at', 'payload'];

    protected static function booted(): void
    {
        static::updating(function (self $webhook) {
            $locked = array_diff(array_keys($webhook->getDirty()), self::MUTABLE);
            if ($locked !== [] || ($webhook->isDirty('payload') && $webhook->payload !== null)) {
                throw new LogicException('Webhook records are append-only (payload may only be cleared).');
            }
        });
        static::deleting(fn () => throw new LogicException('Webhook records are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'outcome' => WebhookOutcome::class,
            'signature_valid' => 'boolean',
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<PaymentGateway, $this> */
    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'payment_gateway_id');
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
