<?php

namespace App\Models;

use App\Support\Enums\SettingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the settings store. Read and write values through
 * App\Services\Settings\SettingsStore, which handles casting, encryption and caching.
 */
class Setting extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'label',
        'description',
        'is_public',
        'is_encrypted',
    ];

    /**
     * The raw (possibly encrypted) value is never serialized.
     *
     * @var list<string>
     */
    protected $hidden = ['value'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SettingType::class,
            'is_public' => 'boolean',
            'is_encrypted' => 'boolean',
        ];
    }

    /** @return BelongsTo<SystemUser, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'updated_by');
    }
}
