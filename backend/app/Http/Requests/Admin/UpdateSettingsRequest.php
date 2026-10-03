<?php

namespace App\Http\Requests\Admin;

use App\Models\Setting;
use App\Support\Settings\SettingDefinitions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;

/**
 * Validates the admin settings form. Fields are posted as settings[<field>],
 * where <field> is the setting key with "." replaced by "__" (dots would be
 * read as nesting by the validator). Rules come from each setting's type plus
 * any extra rules in SettingDefinitions. Unknown keys are ignored.
 */
class UpdateSettingsRequest extends FormRequest
{
    /** @var Collection<int, Setting>|null */
    private ?Collection $settings = null;

    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /** @return Collection<int, Setting> */
    public function settings(): Collection
    {
        return $this->settings ??= Setting::orderBy('group')->orderBy('key')->get();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = ['settings' => ['required', 'array']];

        foreach ($this->settings() as $setting) {
            $extra = SettingDefinitions::extraRules($setting->key);

            $rules['settings.'.self::field($setting->key)] = [
                // Encrypted settings are write-only (blank keeps the current value);
                // others are required unless their definition allows blank ('nullable').
                $setting->is_encrypted || in_array('nullable', $extra, true) ? 'nullable' : 'required',
                ...$setting->type->rules(),
                ...array_values(array_diff($extra, ['nullable'])),
            ];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return $this->settings()
            ->mapWithKeys(fn (Setting $s) => ['settings.'.self::field($s->key) => $s->label ?? $s->key])
            ->all();
    }

    /**
     * Submitted values keyed by setting key, only for known settings that were
     * sent. Blank encrypted fields are left out so the stored secret is kept.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $input = $this->validated('settings');
        $values = [];

        foreach ($this->settings() as $setting) {
            $field = self::field($setting->key);

            if (! array_key_exists($field, $input)) {
                continue;
            }
            if ($setting->is_encrypted && ($input[$field] === null || $input[$field] === '')) {
                continue;
            }

            $values[$setting->key] = $input[$field];
        }

        return $values;
    }
}
