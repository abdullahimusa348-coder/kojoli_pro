<?php

namespace App\Models;

use App\Support\Enums\UserStatus;
use App\Support\Enums\UserType;
use App\Support\Wallet\WalletType;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Customer account. Staff/admin accounts are SystemUser (separate table and guard);
 * customers never hold roles or permissions.
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * user_type and status are deliberately excluded: they are set
     * explicitly by Actions or authorized staff, never from request input.
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

    /** Whether customers must verify their email (config nadabo.require_email_verification). */
    public static function emailVerificationRequired(): bool
    {
        return (bool) config('nadabo.require_email_verification');
    }

    /** Only sends while email verification is switched on, so nothing is mailed by default. */
    public function sendEmailVerificationNotification(): void
    {
        if (static::emailVerificationRequired() && ! $this->hasVerifiedEmail()) {
            parent::sendEmailVerificationNotification();
        }
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

    /** @return HasMany<Wallet, $this> */
    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    /** The customer's main wallet, or null until the first credit or debit creates it. */
    public function mainWallet(): ?Wallet
    {
        return $this->wallets()->where('type', WalletType::Main->value)->first();
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
