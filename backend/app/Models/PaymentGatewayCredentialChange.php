<?php

namespace App\Models;

use App\Support\Payments\GatewayMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only gateway credential events (set, replaced, cleared). Never holds values or hints. */
class PaymentGatewayCredentialChange extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Credential history is append-only.'));
        static::deleting(fn () => throw new LogicException('Credential history is append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['mode' => GatewayMode::class];
    }

    /** @return BelongsTo<SystemUser, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'changed_by')->withTrashed();
    }
}
