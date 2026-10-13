<?php

namespace App\Models;

use App\Support\Providers\CredentialKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only credential events (set, replaced, cleared). Never holds values or hints. */
class ProviderCredentialChange extends Model
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
        return ['key' => CredentialKey::class];
    }

    /** @return BelongsTo<SystemUser, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'changed_by')->withTrashed();
    }
}
