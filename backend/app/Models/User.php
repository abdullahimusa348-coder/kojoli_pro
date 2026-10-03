<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /** Permission that grants entry to the admin area. */
    public const ADMIN_ACCESS = 'admin.access';

    /**
     * The attributes that are mass assignable.
     *
     * user_type and status are deliberately excluded: they are set
     * explicitly by Actions or admins, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'last_login_ip',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'user_type' => UserType::class,
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
        return Attribute::make(set: fn (?string $value) => $value === null || trim($value) === '' ? null : static::normalizePhone($value));
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isType(UserType $type): bool
    {
        return $this->user_type === $type;
    }

    public function canAccessAdmin(): bool
    {
        return $this->isActive() && $this->can(self::ADMIN_ACCESS);
    }

    /** @param  Builder<User>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', UserStatus::Active->value);
    }

    /** Find a user by email address or phone number. */
    public static function findByLogin(string $login): ?self
    {
        $login = trim($login);

        return str_contains($login, '@')
            ? static::where('email', mb_strtolower($login))->first()
            : static::where('phone', static::normalizePhone($login))->first();
    }

    /** Store Nigerian numbers in one form: 08012345678 / +2348012345678 -> 08012345678. */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '234') && strlen($digits) === 13) {
            return '0'.substr($digits, 3);
        }

        return $digits;
    }
}
