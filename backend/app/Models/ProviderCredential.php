<?php

namespace App\Models;

use App\Support\Providers\CredentialKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One encrypted provider credential (Laravel encryption with APP_KEY). The
 * value is write-only: hidden from serialization and never rendered; only a
 * last-four-character hint (for values of 8+ characters) is shown.
 */
class ProviderCredential extends Model
{
    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['value'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['key' => CredentialKey::class, 'value' => 'encrypted'];
    }

    /** @return BelongsTo<Provider, $this> */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /** @return BelongsTo<SystemUser, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'updated_by')->withTrashed();
    }

    public static function hintFor(string $value): ?string
    {
        return mb_strlen($value) >= 8 ? mb_substr($value, -4) : null;
    }

    public function maskedHint(): string
    {
        return '••••'.($this->hint ?? '');
    }
}
