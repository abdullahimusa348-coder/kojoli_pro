<?php

namespace App\Models;

use App\Support\Enums\SystemPermission;
use App\Support\Enums\SystemRole;
use App\Support\Enums\UserStatus;
use Database\Factories\SystemUserFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Staff / admin account. Completely separate from customer accounts (User):
 * own table, own `admin` session guard, own session cookie, own roles.
 * Staff have no API tokens.
 */
class SystemUser extends Authenticatable
{
    /** @use HasFactory<SystemUserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    /** Guard used for spatie roles/permissions. */
    protected string $guard_name = 'admin';

    /**
     * status is excluded on purpose: it is set explicitly, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
        'last_login_ip',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }

    /** @return Attribute<?string, ?string> */
    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null || trim($value) === '' ? null : User::normalizePhone($value));
    }

    /** Deleted (soft-deleted) staff are never active. */
    public function isActive(): bool
    {
        return $this->status === UserStatus::Active && ! $this->trashed();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(SystemRole::SuperAdmin->value);
    }

    public function canAccessAdmin(): bool
    {
        return $this->isActive() && $this->can(SystemPermission::AdminAccess->value);
    }

    /** The staff member's role (one role per staff account). */
    public function primaryRole(): ?SystemRole
    {
        return SystemRole::tryFrom((string) $this->getRoleNames()->first());
    }

    /** Human-readable role names, e.g. "Manager". */
    public function roleLabels(): string
    {
        return $this->getRoleNames()
            ->map(fn (string $name) => SystemRole::tryFrom($name)?->label() ?? $name)
            ->implode(', ');
    }
}
