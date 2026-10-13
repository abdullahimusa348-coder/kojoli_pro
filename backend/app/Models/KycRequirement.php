<?php

namespace App\Models;

use App\Support\Enums\UserType;
use App\Support\Kyc\KycRequirementType;
use Database\Factories\KycRequirementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One KYC requirement (Phase 13 CP1): a phone, BVN, NIN or provider-document
 * requirement. Off by default, with the customer types it applies to. Its
 * purposes are stored for later gating; none is defined yet and nothing reads
 * them. Every change is written to KycRequirementChange. Its key and type never
 * change, and it is never deleted. Turning a requirement on records the
 * configuration only: nothing enforces it in this version.
 */
class KycRequirement extends Model
{
    /** @use HasFactory<KycRequirementFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        static::saving(fn (self $requirement) => $requirement->enforceInvariants());
        static::deleting(fn () => throw new LogicException('KYC requirements are never deleted.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'user_types' => 'array',
            'purposes' => 'array',
            'position' => 'integer',
            'updated_by' => 'integer',
        ];
    }

    /** Requirements are addressed by their key in URLs, never by their id. */
    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /** @return BelongsTo<SystemUser, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class, 'updated_by')->withTrashed();
    }

    /** @return HasMany<KycRequirementChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(KycRequirementChange::class, 'kyc_requirement_id');
    }

    public function requirementType(): KycRequirementType
    {
        return KycRequirementType::from((string) $this->attributes['type']);
    }

    /** Whether the requirement is set for customers of this type, on or off. */
    public function appliesTo(UserType $type): bool
    {
        return in_array($type->value, $this->user_types ?? [], true);
    }

    /** Whether the requirement is on for customers of this type. Nothing reads this yet. */
    public function isRequiredFor(UserType $type): bool
    {
        return (bool) $this->is_enabled && $this->appliesTo($type);
    }

    /**
     * The configuration as the history stores it: the same keys in the same order every time, so a before and
     * after can be compared.
     *
     * @return array{label: string, description: ?string, is_enabled: bool, user_types: list<string>, purposes: list<string>}
     */
    public function snapshot(): array
    {
        return [
            'label' => $this->label,
            'description' => $this->description,
            'is_enabled' => (bool) $this->is_enabled,
            'user_types' => array_values($this->user_types ?? []),
            'purposes' => array_values($this->purposes ?? []),
        ];
    }

    private function enforceInvariants(): void
    {
        if ($this->exists && ($this->isDirty('key') || $this->isDirty('type'))) {
            throw new LogicException('A KYC requirement keeps its key and type.');
        }
        if (KycRequirementType::tryFrom((string) ($this->attributes['type'] ?? '')) === null) {
            throw new LogicException('A KYC requirement is a phone, BVN, NIN or provider-document requirement.');
        }
        if (preg_match('/^[a-z][a-z0-9-]{1,39}$/', (string) $this->key) !== 1) {
            throw new LogicException('A KYC requirement key is 2 to 40 lower-case letters, digits or hyphens, starting with a letter.');
        }
        $length = mb_strlen((string) $this->label);
        if ($length < 2 || $length > 120) {
            throw new LogicException('A KYC requirement has a name of 2 to 120 characters.');
        }
        if ($this->description !== null && mb_strlen($this->description) > 500) {
            throw new LogicException('A KYC requirement has a description of up to 500 characters.');
        }

        $types = $this->user_types ?? [];
        if ($types !== array_values(array_intersect(UserType::values(), $types))) {
            throw new LogicException('A KYC requirement applies to known customer types, each once, in the usual order.');
        }
        $purposes = $this->purposes ?? [];
        if (count(array_unique($purposes)) !== count($purposes)
            || array_filter($purposes, fn ($purpose) => ! is_string($purpose) || preg_match('/^[a-z][a-z0-9_]{1,39}$/', $purpose) !== 1) !== []) {
            throw new LogicException('A KYC purpose is a unique lower-case key of 2 to 40 characters.');
        }

        $this->is_enabled = (bool) $this->is_enabled;
        $this->user_types = $types;
        $this->purposes = $purposes;

        if ($this->is_enabled && $types === []) {
            throw new LogicException('A KYC requirement can be on only for at least one customer type.');
        }
    }
}
