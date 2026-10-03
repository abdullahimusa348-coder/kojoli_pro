<?php

namespace App\Support\Enums;

use InvalidArgumentException;

/**
 * Value types for the settings store. Values are stored as text and cast
 * on read; decimals stay strings so no precision is lost.
 */
enum SettingType: string
{
    case String = 'string';
    case Text = 'text';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Json = 'json';

    /** Cast a stored (text) value to its PHP type. */
    public function cast(?string $stored): mixed
    {
        if ($stored === null) {
            return null;
        }

        return match ($this) {
            self::String, self::Text => $stored,
            self::Integer => (int) $stored,
            self::Decimal => $stored,
            self::Boolean => $stored === '1',
            self::Json => json_decode($stored, true, 512, JSON_THROW_ON_ERROR),
        };
    }

    /**
     * Convert a PHP or form value to its stored text form.
     *
     * @throws InvalidArgumentException when the value does not fit the type
     */
    public function serialize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::String, self::Text => is_scalar($value) ? (string) $value : throw $this->invalid($value),
            self::Integer => filter_var($value, FILTER_VALIDATE_INT) !== false && ! is_bool($value)
                ? (string) (int) $value
                : throw $this->invalid($value),
            self::Decimal => is_numeric($value) && ! is_bool($value) ? (string) $value : throw $this->invalid($value),
            self::Boolean => match (true) {
                is_bool($value) => $value ? '1' : '0',
                in_array($value, [1, '1', 'true', 'on', 'yes'], true) => '1',
                in_array($value, [0, '0', 'false', 'off', 'no', ''], true) => '0',
                default => throw $this->invalid($value),
            },
            self::Json => $this->serializeJson($value),
        };
    }

    /** Base validation rules for a submitted form value. */
    public function rules(): array
    {
        return match ($this) {
            self::String => ['string', 'max:255'],
            self::Text => ['string', 'max:10000'],
            self::Integer => ['integer'],
            self::Decimal => ['numeric'],
            self::Boolean => ['boolean'],
            self::Json => ['json'],
        };
    }

    /** Infer the type of a PHP value (used when set() creates a new key). */
    public static function infer(mixed $value): self
    {
        return match (true) {
            is_bool($value) => self::Boolean,
            is_int($value) => self::Integer,
            is_float($value) => self::Decimal,
            is_array($value) => self::Json,
            default => self::String,
        };
    }

    private function serializeJson(mixed $value): string
    {
        if (is_string($value)) {
            json_decode($value);

            return json_last_error() === JSON_ERROR_NONE ? $value : throw $this->invalid($value);
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function invalid(mixed $value): InvalidArgumentException
    {
        return new InvalidArgumentException('Value of type '.get_debug_type($value)." is not a valid {$this->value} setting.");
    }
}
