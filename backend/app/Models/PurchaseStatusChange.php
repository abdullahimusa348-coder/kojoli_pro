<?php

namespace App\Models;

use App\Support\Purchases\PurchaseSource;
use App\Support\Purchases\PurchaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only purchase status history. Never updated or deleted. */
class PurchaseStatusChange extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Purchase history is append-only.'));
        static::deleting(fn () => throw new LogicException('Purchase history is append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['old_status' => PurchaseStatus::class, 'new_status' => PurchaseStatus::class, 'source' => PurchaseSource::class];
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    /** @return BelongsTo<SystemUser, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'changed_by')->withTrashed();
    }
}
