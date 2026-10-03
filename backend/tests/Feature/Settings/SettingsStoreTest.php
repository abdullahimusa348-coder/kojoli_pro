<?php

use App\Models\Setting;
use App\Services\Settings\SettingsStore;
use App\Support\Enums\SettingType;
use Database\Seeders\SettingsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

function store(): SettingsStore
{
    return app(SettingsStore::class);
}

it('creates the settings table with the required columns', function () {
    expect(Schema::hasTable('settings'))->toBeTrue()
        ->and(Schema::hasColumns('settings', [
            'id', 'key', 'value', 'type', 'group', 'label', 'description',
            'is_public', 'is_encrypted', 'updated_by', 'created_at', 'updated_at',
        ]))->toBeTrue();
});

it('seeds the safe defaults with the right types', function () {
    $this->seed(SettingsSeeder::class);

    expect(store()->all())->toBe([
        'app.currency' => 'NGN',
        'app.currency_symbol' => '₦',
        'app.maintenance_mode' => false,
        'app.name' => 'Nadabo Global Data',
        'app.timezone' => 'Africa/Lagos',
        'pricing.max_amount_kobo' => 1000000000,
    ]);

    expect(Setting::firstWhere('key', 'app.maintenance_mode')->type)->toBe(SettingType::Boolean)
        ->and(Setting::where('is_encrypted', true)->count())->toBe(0)
        ->and(Setting::firstWhere('key', 'pricing.max_amount_kobo')->type)->toBe(SettingType::Integer)
        ->and(Setting::pluck('group')->unique()->sort()->values()->all())->toBe(['app', 'pricing']);
});

it('does not overwrite values changed by staff when re-seeded', function () {
    $this->seed(SettingsSeeder::class);
    store()->set('app.name', 'Nadabo Data');

    $this->seed(SettingsSeeder::class);

    expect(store()->get('app.name'))->toBe('Nadabo Data')->and(Setting::count())->toBe(6);
});

it('gets, sets, checks and forgets values', function () {
    expect(store()->has('demo.flag'))->toBeFalse()
        ->and(store()->get('demo.flag'))->toBeNull()
        ->and(store()->get('demo.flag', 'fallback'))->toBe('fallback');

    store()->set('demo.flag', true);

    expect(store()->has('demo.flag'))->toBeTrue()
        ->and(store()->get('demo.flag'))->toBeTrue()
        ->and(Setting::firstWhere('key', 'demo.flag')->group)->toBe('demo');

    store()->forget('demo.flag');

    expect(store()->has('demo.flag'))->toBeFalse()
        ->and(Setting::where('key', 'demo.flag')->exists())->toBeFalse();
});

it('casts every supported type', function (SettingType $type, mixed $input, mixed $expected) {
    Setting::create(['key' => 'demo.value', 'type' => $type, 'group' => 'demo']);

    store()->set('demo.value', $input);
    store()->flush();

    expect(store()->get('demo.value'))->toBe($expected);
})->with([
    'string' => [SettingType::String, 'Lagos', 'Lagos'],
    'text' => [SettingType::Text, "Line one\nLine two", "Line one\nLine two"],
    'integer' => [SettingType::Integer, '42', 42],
    'integer from int' => [SettingType::Integer, 7, 7],
    'decimal keeps precision' => [SettingType::Decimal, '2.50', '2.50'],
    'boolean true from form' => [SettingType::Boolean, '1', true],
    'boolean false from form' => [SettingType::Boolean, '0', false],
    'boolean native' => [SettingType::Boolean, false, false],
    'json from array' => [SettingType::Json, ['mtn' => 1, 'glo' => [2, 3]], ['mtn' => 1, 'glo' => [2, 3]]],
    'json from string' => [SettingType::Json, '{"a":true}', ['a' => true]],
]);

it('infers the type of new keys', function () {
    store()->set('demo.int', 5);
    store()->set('demo.rate', 1.5);
    store()->set('demo.list', ['a']);
    store()->set('demo.on', true);
    store()->set('demo.label', 'x');

    expect(Setting::orderBy('key')->pluck('type', 'key')->map->value->all())->toBe([
        'demo.int' => 'integer',
        'demo.label' => 'string',
        'demo.list' => 'json',
        'demo.on' => 'boolean',
        'demo.rate' => 'decimal',
    ]);
});

it('rejects values that do not fit the stored type', function (SettingType $type, mixed $bad) {
    Setting::create(['key' => 'demo.value', 'type' => $type, 'group' => 'demo', 'value' => null]);

    expect(fn () => store()->set('demo.value', $bad))->toThrow(InvalidArgumentException::class);
})->with([
    'integer' => [SettingType::Integer, 'abc'],
    'integer decimal' => [SettingType::Integer, '1.5'],
    'decimal' => [SettingType::Decimal, 'ten'],
    'boolean' => [SettingType::Boolean, 'maybe'],
    'json' => [SettingType::Json, '{broken'],
    'string array' => [SettingType::String, ['a']],
]);

it('returns settings by group', function () {
    store()->set('app.name', 'Nadabo');
    store()->set('mail.from_name', 'Nadabo Support');
    store()->set('mail.enabled', false);

    expect(store()->group('mail'))->toBe(['mail.enabled' => false, 'mail.from_name' => 'Nadabo Support'])
        ->and(store()->group('missing'))->toBe([]);
});

it('caches settings and clears the cache on write', function () {
    store()->set('demo.name', 'one');
    store()->get('demo.name');

    expect(Cache::has(SettingsStore::CACHE_KEY))->toBeTrue();

    store()->set('demo.name', 'two');

    expect(Cache::has(SettingsStore::CACHE_KEY))->toBeFalse()
        ->and(store()->get('demo.name'))->toBe('two');
});

it('encrypts sensitive settings at rest and keeps them out of public values', function () {
    Setting::create(['key' => 'demo.api_secret', 'type' => SettingType::String, 'group' => 'demo', 'is_encrypted' => true, 'is_public' => true]);
    store()->set('demo.public_name', 'Nadabo');
    Setting::where('key', 'demo.public_name')->update(['is_public' => true]);
    store()->flush();

    store()->set('demo.api_secret', 'not-a-real-secret');

    $raw = Setting::firstWhere('key', 'demo.api_secret')->getRawOriginal('value');
    expect($raw)->not->toContain('not-a-real-secret')
        ->and(store()->get('demo.api_secret'))->toBe('not-a-real-secret')
        ->and(store()->publicValues())->toBe(['demo.public_name' => 'Nadabo'])
        ->and(Setting::firstWhere('key', 'demo.api_secret')->toArray())->not->toHaveKey('value');
});

it('exposes no settings endpoint on the customer API', function () {
    $this->getJson('/api/v1/settings')->assertNotFound();
});
