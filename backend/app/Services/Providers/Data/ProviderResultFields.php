<?php

namespace App\Services\Providers\Data;

use Countable;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use WeakMap;

/**
 * Text result fields a provider delivered with a successful purchase (Phase
 * 11 CP2): an ordered list of key, label and value, mapped by the adapter
 * from the provider's documented response. The engine defines no field names.
 *
 * Validated on construction; an invalid set throws, which ProviderCaller turns
 * into an unknown outcome, so it is never stored:
 * - 1 to 50 fields, each with a key, a label and a value;
 * - keys match ^[a-z][a-z0-9_]{0,49}$ and are unique;
 * - labels have 1 to 100 characters; values are text of up to 1,000;
 * - no control characters, and no media (data: URIs or base64-like payloads).
 *
 * Never printable: the values are kept outside the object's properties, so
 * debug output, var_export, casts and dumps show only the field count, JSON
 * gives only the count, and the object cannot be serialised or cloned.
 */
final class ProviderResultFields implements Countable, JsonSerializable
{
    public const MAX_FIELDS = 50;

    public const MAX_LABEL = 100;

    public const MAX_VALUE = 1000;

    private const KEY = '/\A[a-z][a-z0-9_]{0,49}\z/';

    /** A data: URI (data:[<media type>][;params],…), anywhere in a value. */
    private const DATA_URI = '/\bdata:[a-z0-9.+\/-]*(?:;[a-z0-9=.+-]+)*,/i';

    /** A run of 200 or more base64 characters is media, never text. */
    private const BASE64_RUN = '/[A-Za-z0-9+\/_-]{200,}/';

    /** @var WeakMap<self, list<array{key: string, label: string, value: string}>>|null */
    private static ?WeakMap $values = null;

    private readonly int $count;

    /** @param  array<mixed>  $fields  list of ['key' => …, 'label' => …, 'value' => …] */
    public function __construct(#[\SensitiveParameter] array $fields)
    {
        $checked = self::check($fields);
        $this->count = count($checked);
        self::$values ??= new WeakMap;
        self::$values[$this] = $checked;
    }

    /**
     * The fields, for the purchase engine to store and for the buyer's own
     * result page; never for logs, messages or staff.
     *
     * @return list<array{key: string, label: string, value: string}>
     */
    public function all(): array
    {
        return self::$values[$this];
    }

    public function count(): int
    {
        return $this->count;
    }

    /** @return array{fields: int} */
    public function jsonSerialize(): array
    {
        return ['fields' => $this->count];
    }

    /** @return array{fields: int} */
    public function __debugInfo(): array
    {
        return ['fields' => $this->count];
    }

    public function __serialize(): array
    {
        throw new LogicException('Provider result fields are never serialised.');
    }

    private function __clone() {}

    /**
     * @param  array<mixed>  $fields
     * @return list<array{key: string, label: string, value: string}>
     */
    private static function check(#[\SensitiveParameter] array $fields): array
    {
        if (! array_is_list($fields) || $fields === [] || count($fields) > self::MAX_FIELDS) {
            throw new InvalidArgumentException('A result has 1 to '.self::MAX_FIELDS.' fields.');
        }

        $checked = [];
        foreach ($fields as $i => $field) {
            $n = $i + 1;
            $shape = is_array($field) ? array_keys($field) : [];
            sort($shape);
            if ($shape !== ['key', 'label', 'value']) {
                throw new InvalidArgumentException("Result field {$n} needs exactly a key, a label and a value.");
            }
            ['key' => $key, 'label' => $label, 'value' => $value] = $field;
            if (! is_string($key) || preg_match(self::KEY, $key) !== 1) {
                throw new InvalidArgumentException("Result field {$n} has an invalid key.");
            }
            if (isset($checked[$key])) {
                throw new InvalidArgumentException("Result field {$n} repeats a key.");
            }
            if (! is_string($label) || ! self::isText($label) || trim($label) === '' || mb_strlen($label) > self::MAX_LABEL) {
                throw new InvalidArgumentException("Result field {$n} needs a label of 1 to ".self::MAX_LABEL.' characters.');
            }
            if (! is_string($value) || ! self::isText($value) || mb_strlen($value) > self::MAX_VALUE) {
                throw new InvalidArgumentException("Result field {$n} needs a text value of up to ".self::MAX_VALUE.' characters.');
            }
            if (preg_match(self::DATA_URI, $value) === 1 || preg_match(self::BASE64_RUN, $value) === 1) {
                throw new InvalidArgumentException("Result field {$n} looks like media, which is never stored.");
            }
            $checked[$key] = ['key' => $key, 'label' => $label, 'value' => $value];
        }

        return array_values($checked);
    }

    /** Valid UTF-8 without control characters. */
    private static function isText(#[\SensitiveParameter] string $text): bool
    {
        return preg_match('/\p{Cc}/u', $text) === 0;
    }
}
