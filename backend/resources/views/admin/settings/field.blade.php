@php
    /** @var \App\Models\Setting $model */
    $name = 'settings['.$field.']';
    $id = 'setting-'.$field;
    $error = $errors->first('settings.'.$field);
    $old = old('settings.'.$field, match (true) {
        is_bool($value) => $value ? '1' : '0',
        is_array($value) => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        default => $value,
    });
    $inputClass = 'block w-full rounded-lg border px-3 py-2 text-sm text-navy-900 shadow-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-200 disabled:bg-navy-50 '.($error ? 'border-red-400' : 'border-navy-200');
@endphp

<div class="grid gap-2 px-5 py-4 sm:grid-cols-5 sm:gap-6" data-setting="{{ $model->key }}">
    <div class="sm:col-span-2">
        <label for="{{ $id }}" class="block text-sm font-medium text-navy-900">{{ $model->label ?? $model->key }}</label>
        @if ($model->description)
            <p class="mt-1 text-xs text-navy-600">{{ $model->description }}</p>
        @endif
        <p class="mt-1 font-mono text-[11px] text-navy-400">{{ $model->key }}</p>
    </div>

    <div class="sm:col-span-3">
        @switch($model->type)
            @case(\App\Support\Enums\SettingType::Boolean)
                <input type="hidden" name="{{ $name }}" value="0">
                <label class="inline-flex items-center gap-2 text-sm text-navy-800">
                    <input id="{{ $id }}" type="checkbox" name="{{ $name }}" value="1" @checked($old === '1' || $old === 1 || $old === true)
                           class="h-4 w-4 rounded border-navy-300 text-brand-600 focus:ring-brand-500">
                    Enabled
                </label>
                @break

            @case(\App\Support\Enums\SettingType::Text)
            @case(\App\Support\Enums\SettingType::Json)
                <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $model->type === \App\Support\Enums\SettingType::Json ? 6 : 4 }}"
                          @class([$inputClass, 'font-mono' => $model->type === \App\Support\Enums\SettingType::Json])
                          @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>{{ $old }}</textarea>
                @break

            @default
                @if ($model->is_encrypted)
                    <input id="{{ $id }}" type="password" name="{{ $name }}" value="" autocomplete="new-password"
                           placeholder="{{ $has_value ? '•••••••• (saved, leave blank to keep)' : 'Not set' }}"
                           class="{{ $inputClass }}" @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
                @else
                    <input id="{{ $id }}" name="{{ $name }}" value="{{ $old }}"
                           type="{{ in_array($model->type, [\App\Support\Enums\SettingType::Integer, \App\Support\Enums\SettingType::Decimal], true) ? 'number' : 'text' }}"
                           @if ($model->type === \App\Support\Enums\SettingType::Decimal) step="any" @endif
                           class="{{ $inputClass }}" @if ($error) aria-invalid="true" aria-describedby="{{ $id }}-error" @endif>
                @endif
        @endswitch

        @if ($error)
            <p id="{{ $id }}-error" class="mt-1 text-sm text-red-600">{{ $error }}</p>
        @endif
    </div>
</div>
