<?php

namespace App\Models;

use App\Support\Payments\PaymentSource;
use App\Support\Payments\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only payment status history. Never updated or deleted. */
class PaymentStatusChange extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payment history is append-only.'));
        static::deleting(fn () => throw new LogicException('Payment history is append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['old_status' => PaymentStatus::class, 'new_status' => PaymentStatus::class, 'source' => PaymentSource::class];
    }

    /** @return BelongsTo<SystemUser, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'changed_by')->withTrashed();
    }
}
