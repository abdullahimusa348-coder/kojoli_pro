<?php

namespace App\Models;

use App\Support\Providers\CostType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only route history (created, updated, enabled, disabled, moved). Never updated or deleted. */
class PlanProviderRouteChange extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Route history is append-only.'));
        static::deleting(fn () => throw new LogicException('Route history is append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'old_priority' => 'integer',
            'new_priority' => 'integer',
            'old_is_active' => 'boolean',
            'new_is_active' => 'boolean',
            'old_cost_type' => CostType::class,
            'new_cost_type' => CostType::class,
            'old_cost_kobo' => 'integer',
            'new_cost_kobo' => 'integer',
            'old_cost_discount_bps' => 'integer',
            'new_cost_discount_bps' => 'integer',
        ];
    }

    /** @return BelongsTo<Provider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /** @return BelongsTo<SystemUser, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'changed_by')->withTrashed();
    }

    /** Short human description of what changed. */
    public function summary(): string
    {
        $newCost = PlanProviderRoute::describeCost($this->new_cost_type, $this->new_cost_kobo, $this->new_cost_discount_bps);

        if ($this->event === 'created') {
            return "Created: priority {$this->new_priority}, code ".($this->new_provider_plan_code ?? '—').", {$newCost}";
        }
        if (in_array($this->event, ['enabled', 'disabled'], true)) {
            return ucfirst($this->event);
        }

        $parts = [];
        if ($this->old_priority !== $this->new_priority) {
            $parts[] = "priority {$this->old_priority} → {$this->new_priority}";
        }
        if ($this->old_provider_plan_code !== $this->new_provider_plan_code) {
            $parts[] = 'code '.($this->old_provider_plan_code ?? '—').' → '.($this->new_provider_plan_code ?? '—');
        }
        $oldCost = PlanProviderRoute::describeCost($this->old_cost_type, $this->old_cost_kobo, $this->old_cost_discount_bps);
        if ($oldCost !== $newCost) {
            $parts[] = "cost {$oldCost} → {$newCost}";
        }

        return ucfirst($this->event).($parts ? ': '.implode(', ', $parts) : '');
    }
}
