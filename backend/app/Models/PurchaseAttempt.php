<?php

namespace App\Models;

use App\Exceptions\Purchases\PurchaseException;
use App\Support\Providers\CostType;
use App\Support\Purchases\PurchaseAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One call to one provider route for a purchase. A route is tried at most once
 * per purchase (unique purchase + route); re-checks query this same attempt.
 * The route snapshot and our request reference never change; the provider's
 * reference is set at most once; status changes follow
 * PurchaseAttemptStatus::canTransitionTo(). Never deleted.
 */
class PurchaseAttempt extends Model
{
    /** @var list<string> */
    protected $fillable = [];

    private const IMMUTABLE = [
        'purchase_id', 'attempt_number', 'plan_provider_route_id', 'provider_id', 'route_priority', 'provider_plan_code',
        'cost_type', 'cost_kobo', 'cost_discount_bps', 'request_reference', 'started_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $attempt) {
            if (! $attempt->exists) {
                if ($attempt->status !== PurchaseAttemptStatus::Started) {
                    throw new PurchaseException('A purchase attempt is always created started.');
                }

                return;
            }
            $locked = array_intersect(array_keys($attempt->getDirty()), self::IMMUTABLE);
            if ($locked !== []) {
                throw new LogicException('Purchase attempt fields are immutable: '.implode(', ', $locked).'.');
            }
            if ($attempt->isDirty('provider_reference') && $attempt->getRawOriginal('provider_reference') !== null) {
                throw new LogicException('The provider reference is set at most once.');
            }
            if ($attempt->isDirty('status')) {
                $from = PurchaseAttemptStatus::from($attempt->getRawOriginal('status'));
                if (! $from->canTransitionTo($attempt->status)) {
                    throw new PurchaseException("A {$from->value} attempt cannot become {$attempt->status->value}.");
                }
            }
        });
        static::deleting(fn () => throw new LogicException('Purchase attempts are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PurchaseAttemptStatus::class,
            'cost_type' => CostType::class,
            'attempt_number' => 'integer',
            'route_priority' => 'integer',
            'cost_kobo' => 'integer',
            'cost_discount_bps' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return BelongsTo<PlanProviderRoute, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(PlanProviderRoute::class, 'plan_provider_route_id');
    }

    /** @return BelongsTo<Provider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
