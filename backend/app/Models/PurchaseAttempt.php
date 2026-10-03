<?php

namespace App\Models;

use App\Exceptions\Purchases\PurchaseException;
use App\Support\Providers\CostType;
use App\Support\Purchases\PurchaseAttemptStatus;
use App\Support\Purchases\PurchaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One call to one provider route for a purchase. A route is tried at most once
 * per purchase (unique purchase + route); re-checks query this same attempt.
 * Invariants (model guards, plus composite foreign keys where the database
 * can express them):
 * - created started, only for a pending purchase, on a route of the
 *   purchase's plan, with the route's provider and an exact snapshot of the
 *   route (priority, provider plan code, cost);
 * - the route snapshot and our request reference never change; the
 *   provider's reference is set at most once;
 * - status changes follow PurchaseAttemptStatus::canTransitionTo();
 * - an attempt with a definite outcome (succeeded or failed_definite) never
 *   changes again; an unknown attempt stays open for provider re-checks.
 * Never deleted.
 */
class PurchaseAttempt extends Model
{
    /** @var list<string> */
    protected $fillable = [];

    private const IMMUTABLE = [
        'purchase_id', 'plan_id', 'attempt_number', 'plan_provider_route_id', 'provider_id', 'route_priority', 'provider_plan_code',
        'cost_type', 'cost_kobo', 'cost_discount_bps', 'request_reference', 'started_at',
    ];

    protected static function booted(): void
    {
        static::saving(fn (self $attempt) => $attempt->exists ? $attempt->guardUpdate() : $attempt->guardCreate());
        static::deleting(fn () => throw new LogicException('Purchase attempts are never deleted.'));
    }

    /**
     * Provider cost of this attempt from the route snapshot: the fixed cost,
     * or for a percent cost the face value minus the discount (discount
     * rounded down, as in PriceResolver). Null when the route had no cost or
     * a percent cost has no face value.
     */
    public function costKobo(?int $faceValueKobo): ?int
    {
        return match ($this->cost_type) {
            CostType::Fixed => $this->cost_kobo,
            CostType::Percent => $faceValueKobo === null || $this->cost_discount_bps === null
                ? null : $faceValueKobo - intdiv($faceValueKobo * $this->cost_discount_bps, 10_000),
            null => null,
        };
    }

    private function guardCreate(): void
    {
        if ($this->status !== PurchaseAttemptStatus::Started) {
            throw new PurchaseException('A purchase attempt is always created started.');
        }
        $purchase = Purchase::find($this->purchase_id);
        if ($purchase === null || $purchase->status !== PurchaseStatus::Pending) {
            throw new PurchaseException('Attempts are only made for a pending purchase.');
        }
        $route = PlanProviderRoute::find($this->plan_provider_route_id);
        if ($route === null || $route->plan_id !== $purchase->plan_id) {
            throw new PurchaseException('The attempt route must belong to the purchase plan.');
        }
        if ($this->provider_id !== $route->provider_id) {
            throw new PurchaseException('The attempt provider must be the route provider.');
        }
        if ($this->plan_id !== null && $this->plan_id !== $purchase->plan_id) {
            throw new PurchaseException('The attempt plan must be the purchase plan.');
        }
        $this->plan_id = $purchase->plan_id;

        if ($this->route_priority !== $route->priority || $this->provider_plan_code !== $route->provider_plan_code
            || $this->cost_type !== $route->cost_type || $this->cost_kobo !== $route->cost_kobo || $this->cost_discount_bps !== $route->cost_discount_bps) {
            throw new PurchaseException('The attempt must hold an exact snapshot of its route.');
        }
    }

    private function guardUpdate(): void
    {
        $from = PurchaseAttemptStatus::from($this->getRawOriginal('status'));
        if ($from->isDefinite() && $this->isDirty()) {
            throw new LogicException('An attempt with a definite outcome can no longer change.');
        }
        $locked = array_intersect(array_keys($this->getDirty()), self::IMMUTABLE);
        if ($locked !== []) {
            throw new LogicException('Purchase attempt fields are immutable: '.implode(', ', $locked).'.');
        }
        if ($this->isDirty('provider_reference') && $this->getRawOriginal('provider_reference') !== null) {
            throw new LogicException('The provider reference is set at most once.');
        }
        if ($this->isDirty('status') && ! $from->canTransitionTo($this->status)) {
            throw new PurchaseException("A {$from->value} attempt cannot become {$this->status->value}.");
        }
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PurchaseAttemptStatus::class,
            'purchase_id' => 'integer',
            'plan_id' => 'integer',
            'plan_provider_route_id' => 'integer',
            'provider_id' => 'integer',
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
