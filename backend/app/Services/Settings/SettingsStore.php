<?php

namespace App\Services\Settings;

use App\Models\Setting;
use App\Models\SystemUser;
use App\Support\Enums\SettingType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Database-backed application settings. Values are cast to their stored type,
 * encrypted settings are decrypted only in memory, and all rows are cached
 * (one cache entry, cleared on every write).
 *
 * Server-side use only: nothing here is exposed to customers or the API.
 * Use publicValues() if a public subset is ever needed; it never includes
 * encrypted settings.
 */
class SettingsStore
{
    public const CACHE_KEY = 'nadabo.settings';

    /** @var array<string, array{value: ?string, type: string, group: string, is_public: bool, is_encrypted: bool}>|null */
    private ?array $rows = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->rows()[$key] ?? null;

        if ($row === null || $row['value'] === null) {
            return $default;
        }

        return $this->castRow($row);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->rows());
    }

    /**
     * Store a value. Existing settings keep their type (the value must fit it);
     * new keys get a type inferred from the value and the group from the key prefix.
     *
     * @throws \InvalidArgumentException when the value does not fit the setting type
     */
    public function set(string $key, mixed $value, ?SystemUser $by = null): void
    {
        $setting = Setting::firstWhere('key', $key) ?? new Setting([
            'key' => $key,
            'type' => SettingType::infer($value),
            'group' => Str::before($key, '.') ?: 'general',
        ]);

        $stored = $setting->type->serialize($value);

        $setting->value = $setting->is_encrypted && $stored !== null ? Crypt::encryptString($stored) : $stored;
        $setting->updated_by = $by?->id;
        $setting->save();

        $this->flush();
    }

    public function forget(string $key): void
    {
        Setting::where('key', $key)->delete();

        $this->flush();
    }

    /** @return array<string, mixed> every setting, cast (and decrypted) */
    public function all(): array
    {
        return array_map(fn (array $row) => $this->castRow($row), $this->rows());
    }

    /** @return array<string, mixed> settings in one group, cast, keyed by full key */
    public function group(string $group): array
    {
        return array_map(
            fn (array $row) => $this->castRow($row),
            array_filter($this->rows(), fn (array $row) => $row['group'] === $group),
        );
    }

    /** @return array<string, mixed> only settings flagged public and not encrypted */
    public function publicValues(): array
    {
        return array_map(
            fn (array $row) => $this->castRow($row),
            array_filter($this->rows(), fn (array $row) => $row['is_public'] && ! $row['is_encrypted']),
        );
    }

    public function flush(): void
    {
        $this->rows = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<string, array{value: ?string, type: string, group: string, is_public: bool, is_encrypted: bool}> */
    private function rows(): array
    {
        // Encrypted values stay encrypted in the cache.
        return $this->rows ??= Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()
            ->orderBy('key')
            ->get(['key', 'value', 'type', 'group', 'is_public', 'is_encrypted'])
            ->mapWithKeys(fn (Setting $s) => [$s->key => [
                'value' => $s->getRawOriginal('value'),
                'type' => $s->type->value,
                'group' => $s->group,
                'is_public' => $s->is_public,
                'is_encrypted' => $s->is_encrypted,
            ]])
            ->all());
    }

    /** @param  array{value: ?string, type: string, is_encrypted: bool}  $row */
    private function castRow(array $row): mixed
    {
        $value = $row['value'];

        if ($value !== null && $row['is_encrypted']) {
            $value = Crypt::decryptString($value);
        }

        return SettingType::from($row['type'])->cast($value);
    }
}
