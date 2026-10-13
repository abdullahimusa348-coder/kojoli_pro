<?php

namespace App\Models;

use App\Support\Payments\GatewayMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One encrypted gateway credential for one mode (Laravel encryption, APP_KEY).
 * Write-only: hidden from serialization and never rendered; only a
 * last-four hint (values of 8+ characters) is shown.
 */
class PaymentGatewayCredential extends Model
{
    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['value'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['mode' => GatewayMode::class, 'value' => 'encrypted'];
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
